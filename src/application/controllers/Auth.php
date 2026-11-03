<?php
require_once(APPPATH."libraries/HttpResp.php");
require_once (BASEPATH."/../vendor/autoload.php");
require_once(APPPATH."third_party/dbAPI/Autoloader.php");

use dbAPI\API\AccessControl;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

dbAPI\Autoloader::register();

/**
 * Class Auth
 * Processes user authentication by username and password and MFA when enabled by user
 * @property CI_Config config
 * @property CI_Loader load
 * @property CI_DB_driver db
 * @property CI_Input input
 */
class Auth extends CI_Controller {
    private const REFRESH_TABLE = 'dbapi_refresh_tokens';

    private $configDir;

    function __construct()
    {
        parent::__construct();
        $this->config->load("dbapiator");
        $this->configDir = $this->config->item("configs_dir");
    }

    /**
     * @param $configName
     * @return array
     */
    private function load_auth_config($configName): array
    {
        $cfgDir = $this->configDir."/$configName";

        if(!is_dir($cfgDir)) {
            HttpResp::not_found("API not found");
        }

        $authFile = $cfgDir . '/authentication.php';
        $auth = is_file($authFile) ? include $authFile : false;
        if(!$auth) {
            HttpResp::bad_request(["error"=>"No authentication mechanism configured"]);
        }

        return $this->normalize_auth_config($auth);
    }

    /**
     * Connects to database
     * @param $configName
     * @return mixed
     */
    private function db_connect($configName) {
        $auth = $this->load_auth_config($configName);

        $cfgDir = $this->configDir."/$configName";
        $connFile = $cfgDir . '/connection.php';
        $conn = include_connection_config($connFile);
        if (!is_array($conn)) {
            HttpResp::service_unavailable(["errors" => [["message" => "Could not connect to database"]]]);
        }
        $conn["db_debug"] = FALSE;
        try {
            $this->load->database($conn);
        }
        catch (Exception $exception) {
            HttpResp::service_unavailable(["errors"=>[["message"=>"Could not connect to database"]]]);
        }
        
        if($this->db->error()["code"]!==0) {
            HttpResp::service_unavailable(["errors"=>[["message"=>"Could not connect to database"]]]);
        }
        return $auth;
    }

    /**
     * Create MFA session by generating a token and a verification code
     * The code is sent tu user over selected channel (currently only email is implemented)
     * @param $auth
     * @param $payload
     * @throws Exception
     */
    private function mfa_session_create($auth, $payload) {
        $verification_code = random_int(100000, 999999);
        $this->load->helper("string_helper");
        $session_token = random_string("md5",30);
        if($payload["email"]) {
            $this->send_code_per_email($auth,$payload,$session_token,$verification_code);

            $sql = str_replace(
                ["[[username]]","[[token]]","[[code]]","[[expire]]"],
                [$payload["unm"],$session_token,$verification_code,time()+300],
                $auth["mfaCreateTokenSql"]);
            $this->db->query($sql);

            $jwt = JWT::encode([
                "unm"=>$payload["unm"],
                "mfatoken" =>$session_token
            ], $auth["key"], $auth['alg'] ?? 'HS256');
            HttpResp::json_out(200,["jwt"=>$jwt],["Authorization"=>"Bearer $jwt","Content-type"=>"application/json"]);
            die();
        }
        HttpResp::error_out_json(json_encode($payload),500);
    }

    /**
     * @param $auth
     * @param $payload
     * @param $session_token
     * @param $verification_code
     */
    private function send_code_per_email($auth,$payload,$session_token,$verification_code) {
        $url = $auth["mfaEmailApiUrl"]."/send";
        $data = [
            'token' => $auth["mfaEmailApiToken"],
            'to' => $payload["email"],
            'subject' => str_replace(
                ["[[email]]" , "[[uname]]"],
                [$payload["email"],$payload["unm"]],
                $auth["mfaEmailSubjectTemplate"]),
            'message' => str_replace(
                ["[[email]]" , "[[uname]]","[[cod]]"],
                [$payload["email"],$payload["unm"],$verification_code],
                $auth["mfaEmailMessageTemplate"]),
        ];

        $options = [
            'http' => [
                'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                'method' => 'POST',
                'content' => http_build_query($data),
            ],
        ];

        $context = stream_context_create($options);
        $result = file_get_contents($url, false, $context);
        if ($result === false) {
            HttpResp::server_error("Could not send email with verification code. Please contact the administrator: ".print_r($options));
        }
    }

    /**
     * @param $configName
     */
    function mfa_code_verify($configName) {
        $auth = $this->db_connect($configName);
        $uname = $this->input->post($auth["loginUserInputField"]);
        $token = $this->input->post("token");
        $code = $this->input->post("code");

        $sql = str_replace(
            ["[[username]]","[[token]]","[[code]]"],
            [$uname,$token,$code],
            $auth["mfaVerifyCodeByUpdateSql"]
        );
        $this->db->query($sql);
        if($this->db->affected_rows()) {
            $sql = str_replace(
                "[[username]]",
                $uname,
                $auth["mfaVerifiedOKGetUserQuery"]);

            /** @var CI_DB_result $res */
            $res = $this->db->query($sql);
            $result = $res->row_array();

            $this->generate_token($result,$auth);
        }
        else {
            HttpResp::error_out_json("Invalid or expired code",400);
        }
    }

    /**
     * @param $userId
     * @param $validity
     */
    function genApiClientToken($configName,$uname,$validity) {
        $auth = $this->db_connect($configName);
        $sql = str_replace(
            "[[username]]",
            $uname,
            $auth["mfaVerifiedOKGetUserQuery"]);
        /** @var CI_DB_result $res */
        $res = $this->db->query($sql);
        $result = $res->row_array();

        if(!$result)
            HttpResp::not_found();
        $this->generate_token($result,$auth);
    }

    /**
     * @param array $payload
     * @param array $auth
     */
    private function generate_token($payload, $auth) {
        $validity = (int) ($auth["validity"] ?? 3600);
        $claims = $payload;
        unset($claims["exp"], $claims["iat"]);

        $access = $claims;
        $access["exp"] = time() + $validity;
        $jwt = JWT::encode($access, $auth["jwt_key"], "HS256");
        $body = [
            "access_token" => $jwt,
            "expires_in" => $validity,
            "token_type" => "Bearer",
        ];

        $refreshValidity = (int) ($auth["refresh_validity"] ?? 0);
        if ($refreshValidity > 0) {
            $this->ensure_refresh_table();
            $refreshToken = bin2hex(random_bytes(32));
            $this->db->insert(self::REFRESH_TABLE, [
                "token_hash" => hash("sha256", $refreshToken),
                "payload" => json_encode($claims, JSON_UNESCAPED_UNICODE),
                "expires_at" => time() + $refreshValidity,
            ]);
            $body["refresh_token"] = $refreshToken;
            $body["refresh_expires_in"] = $refreshValidity;
        }

        HttpResp::json_out(200, $body);
    }

    private function ensure_refresh_table(): void
    {
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `" . self::REFRESH_TABLE . "` (
                `token_hash` CHAR(64) NOT NULL,
                `payload` LONGTEXT NOT NULL,
                `expires_at` INT UNSIGNED NOT NULL,
                PRIMARY KEY (`token_hash`),
                KEY `expires_at` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    private function refresh_table_ready(): bool
    {
        $q = $this->db->query('SHOW TABLES LIKE ' . $this->db->escape(self::REFRESH_TABLE));
        return $q && $q->num_rows() > 0;
    }

    /**
     * @return string
     */
    private function posted_refresh_token(): string
    {
        return trim((string) ($this->input->post("refresh_token") ?? ""));
    }

    /**
     * Apply per-method access/refresh TTLs onto the auth config used for token issue.
     *
     * @param array $auth
     * @param array $methodConfig
     * @return array
     */
    private function apply_method_token_ttl(array $auth, array $methodConfig): array
    {
        if (isset($methodConfig["validity"])) {
            $auth["validity"] = (int) $methodConfig["validity"];
        }
        if (array_key_exists("refresh_validity", $methodConfig)) {
            $auth["refresh_validity"] = (int) $methodConfig["refresh_validity"];
        }
        return $auth;
    }

    /**
     * Backward compat: legacy loginQuery becomes loginMethods.password.
     * @param array $auth
     * @return array
     */
    private function normalize_auth_config(array $auth): array
    {
        if (empty($auth['loginMethods']) && !empty($auth['loginQuery'])) {
            $auth['loginMethods'] = [
                'password' => ['loginQuery' => $auth['loginQuery']],
            ];
        }
        if (empty($auth['jwt_key']) && !empty($auth['key'])) {
            $auth['jwt_key'] = $auth['key'];
        }
        return $auth;
    }

    /**
     * @param string $query
     * @return string[]
     */
    private function placeholders_in_query(string $query): array
    {
        preg_match_all('/\[\[(\w+)\]\]/', $query, $matches);
        return array_values(array_unique($matches[1]));
    }

    /**
     * @param array $auth
     * @param string $method
     * @param array $methodConfig
     * @return array{0:CI_DB_result,1:array}
     */
    private function fetch_user_by_method(array $auth, string $method, array $methodConfig): array
    {
        $query = $methodConfig['loginQuery'] ?? null;
        if (empty($query)) {
            HttpResp::bad_request(['error' => 'Login method is not configured', 'method' => $method]);
        }

        $fields = $methodConfig['fields'] ?? $this->placeholders_in_query($query);
        if (empty($fields)) {
            HttpResp::bad_request(['error' => 'Login method has no input fields configured', 'method' => $method]);
        }

        $replacements = [];
        foreach ($fields as $field) {
            $value = $this->input->post($field);
            if ($value === null || $value === '') {
                HttpResp::bad_request(['error' => "Missing required field: {$field}", 'method' => $method]);
            }
            $replacements["[[{$field}]]"] = $value;
        }

        $sql = strtr($query, $replacements);
        /** @var CI_DB_result $res */
        $res = $this->db->query($sql);

        return [$res, $this->apply_method_token_ttl($auth, $methodConfig)];
    }

    /**
     * @param string $name
     * @param array $methodConfig
     * @param array $auth
     * @return array
     */
    private function public_login_method_descriptor(string $name, array $methodConfig, array $auth): array
    {
        $query = $methodConfig['loginQuery'] ?? '';
        $fields = $methodConfig['fields'] ?? $this->placeholders_in_query($query);

        $descriptor = [
            'name' => $name,
            'fields' => $fields,
        ];

        $validity = $methodConfig['validity'] ?? ($auth['validity'] ?? null);
        if ($validity !== null) {
            $descriptor['expiresIn'] = (int) $validity;
        }

        $refreshValidity = $methodConfig['refresh_validity'] ?? ($auth['refresh_validity'] ?? 0);
        if ((int) $refreshValidity > 0) {
            $descriptor['refreshExpiresIn'] = (int) $refreshValidity;
        }

        return $descriptor;
    }

    /**
     * Exchange a client token and secret for a short-lived access JWT.
     * No refresh token: the secret is the long-lived credential.
     *
     * @param $configName
     */
    function client($configName) {
        $auth = $this->load_auth_config($configName);
        if (($auth['mode'] ?? 'none') === 'none' || empty($auth['jwt_key'])) {
            HttpResp::bad_request(['error' => 'API clients require dbAuth with a JWT key']);
        }

        $token = trim((string) $this->input->post('token'));
        $secret = (string) $this->input->post('secret');
        $path = $this->configDir . '/' . $configName . '/' . \dbAPI\Auth\ApiClients::FILE;
        $clients = is_file($path) ? include $path : [];
        if (!is_array($clients)) {
            $clients = [];
        }
        $row = \dbAPI\Auth\ApiClients::verify($clients, $token, $secret);
        if ($row === null) {
            HttpResp::json_out(401, ['errors' => [['message' => 'Authentication failed']]]);
        }

        $validity = (int) ($row['validity'] ?? \dbAPI\Auth\ApiClients::DEFAULT_VALIDITY);
        $access = \dbAPI\Auth\ApiClients::accessClaims($token, $row);
        $access['exp'] = time() + $validity;
        $jwt = JWT::encode($access, $auth['jwt_key'], 'HS256');
        HttpResp::json_out(200, [
            'access_token' => $jwt,
            'expires_in' => $validity,
            'token_type' => 'Bearer',
        ]);
    }

    /**
     * @param $configName
     */
    function login_methods($configName) {
        $auth = $this->load_auth_config($configName);

        if (($auth['mode'] ?? null) === 'none') {
            HttpResp::json_out(200, ['loginMethods' => []]);
            return;
        }

        $methods = [];
        foreach ($auth['loginMethods'] ?? [] as $name => $methodConfig) {
            if (!is_array($methodConfig) || empty($methodConfig['loginQuery'])) {
                continue;
            }
            $methods[] = $this->public_login_method_descriptor($name, $methodConfig, $auth);
        }

        HttpResp::json_out(200, ['loginMethods' => $methods]);
    }

    /**
     * @param $configName
     * @param $loginMethod
     * @throws Exception
     */
    function login($configName, $loginMethod) {
        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $loginMethod)) {
            HttpResp::bad_request(['error' => 'Invalid login method name', 'method' => $loginMethod]);
        }

        $auth = $this->db_connect($configName);

        if (empty($auth['loginMethods']) || !is_array($auth['loginMethods'])) {
            HttpResp::bad_request(['error' => 'No login methods configured']);
        }

        if (empty($auth['loginMethods'][$loginMethod]) || !is_array($auth['loginMethods'][$loginMethod])) {
            HttpResp::not_found(['error' => 'Unknown or disabled login method', 'method' => $loginMethod]);
        }

        [$res, $auth] = $this->fetch_user_by_method($auth, $loginMethod, $auth['loginMethods'][$loginMethod]);
        if($this->db->error()["code"]>0) {
            HttpResp::server_error(["error"=>$this->db->error()]);
        }

        if($res->num_rows()!==1) {
            HttpResp::not_found(["errors"=>["message"=>"Authentication failed","code"=>404]]);
        }

        $result = $res->row_array();
        $result['login_method'] = $loginMethod;

        if (!empty($result['mfa_enabled']))
            $this->mfa_session_create($auth,$result);
        else
            $this->generate_token($result,$auth);

    }

    /**
     * Validate Bearer JWT. Success: 204 No Content. Failure: 401, no body.
     *
     * @param $configName
     */
    function session($configName) {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            HttpResp::quick(405);
        }

        $auth = $this->load_auth_config($configName);
        if (($auth['mode'] ?? null) === 'none') {
            HttpResp::quick(401);
        }

        $headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
        $jwt = AccessControl::decodeJwt($auth, $headers, $_SERVER);

        if (!$jwt['valid']) {
            HttpResp::quick(401);
        }

        HttpResp::no_content(204);
    }

    /**
     * Rotate a refresh token: consume the presented token, issue a new pair.
     * Success: 200 with the same body as login. Failure: 401, no body.
     *
     * @param $configName
     */
    function refresh($configName) {
        if (($_SERVER['REQUEST_METHOD'] ?? 'POST') !== 'POST') {
            HttpResp::quick(405);
        }

        $auth = $this->db_connect($configName);
        if (($auth['mode'] ?? null) === 'none') {
            HttpResp::quick(401);
        }

        $token = $this->posted_refresh_token();
        if ($token === '') {
            HttpResp::bad_request(['error' => 'Missing refresh_token']);
        }

        if (!$this->refresh_table_ready()) {
            HttpResp::quick(401);
        }

        $hash = hash('sha256', $token);
        $row = $this->db->get_where(self::REFRESH_TABLE, ['token_hash' => $hash])->row_array();
        if (!$row || (int) $row['expires_at'] < time()) {
            if ($row) {
                $this->db->delete(self::REFRESH_TABLE, ['token_hash' => $hash]);
            }
            HttpResp::quick(401);
        }

        $this->db->delete(self::REFRESH_TABLE, ['token_hash' => $hash]);
        if ($this->db->affected_rows() !== 1) {
            HttpResp::quick(401);
        }

        $claims = json_decode((string) $row['payload'], true);
        if (!is_array($claims)) {
            HttpResp::quick(401);
        }

        $loginMethod = $claims['login_method'] ?? null;
        if (is_string($loginMethod) && !empty($auth['loginMethods'][$loginMethod]) && is_array($auth['loginMethods'][$loginMethod])) {
            $auth = $this->apply_method_token_ttl($auth, $auth['loginMethods'][$loginMethod]);
        }

        $this->generate_token($claims, $auth);
    }

    /**
     * Revoke a refresh token if presented. Always 204.
     *
     * @param $configName
     */
    function logout($configName) {
        if (($_SERVER['REQUEST_METHOD'] ?? 'POST') !== 'POST') {
            HttpResp::quick(405);
        }

        $this->db_connect($configName);
        $token = $this->posted_refresh_token();
        if ($token !== '' && $this->refresh_table_ready()) {
            $this->db->delete(self::REFRESH_TABLE, ['token_hash' => hash('sha256', $token)]);
        }

        HttpResp::no_content(204);
    }
}
