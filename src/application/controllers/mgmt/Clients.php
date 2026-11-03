<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once APPPATH . 'core/MY_MgmtController.php';

use dbAPI\Auth\ApiClients;

class Clients extends MY_MgmtController
{
    public function index($apiId)
    {
        $this->requireApiAccess($apiId);
        HttpResp::json_out(200, ['clients' => ApiClients::publicList($this->loadClients($apiId))]);
    }

    public function create($apiId)
    {
        $this->requireApiAccess($apiId);
        $payload = json_decode(json_encode($this->validatePayload()), true);
        if (!is_array($payload)) {
            $this->mgmtError(400, $this->errorsCatalog['input']['invalid_json']);
        }
        try {
            $token = ApiClients::assertToken((string) ($payload['token'] ?? ''));
            $claims = ApiClients::normalizeClaims($token, $this->claimsArray($payload['claims'] ?? null));
            $validity = ApiClients::normalizeValidity($payload['validity'] ?? null);
        } catch (InvalidArgumentException $e) {
            $this->invalid($e);
        }
        $clients = $this->loadClients($apiId);
        if (isset($clients[$token])) {
            $this->mgmtError(409, ['code' => 4090, 'message' => 'API client already exists'], ['token' => $token]);
        }
        $issued = ApiClients::issue($claims, $validity);
        $clients[$token] = $issued['row'];
        $this->saveClients($apiId, $clients);
        $out = ApiClients::publicClient($token, $issued['row']);
        $out['secret'] = $issued['secret'];
        HttpResp::json_out(201, $out);
    }

    public function update($apiId, $token)
    {
        $this->requireApiAccess($apiId);
        $payload = json_decode(json_encode($this->validatePayload()), true);
        if (!is_array($payload)) {
            $this->mgmtError(400, $this->errorsCatalog['input']['invalid_json']);
        }
        try {
            $token = ApiClients::assertToken((string) $token);
        } catch (InvalidArgumentException $e) {
            $this->invalid($e);
        }
        $clients = $this->loadClients($apiId);
        if (!isset($clients[$token]) || !is_array($clients[$token])) {
            $this->missing($token);
        }
        $row = $clients[$token];
        $changed = false;
        try {
            if (array_key_exists('claims', $payload)) {
                $row['claims'] = ApiClients::normalizeClaims($token, $this->claimsArray($payload['claims']));
                $changed = true;
            }
            if (array_key_exists('validity', $payload)) {
                $row['validity'] = ApiClients::normalizeValidity($payload['validity']);
                $changed = true;
            }
            if (array_key_exists('enabled', $payload)) {
                $row['enabled'] = (bool) $payload['enabled'];
                $changed = true;
            }
        } catch (InvalidArgumentException $e) {
            $this->invalid($e);
        }
        if (!$changed) {
            $this->mgmtError(400, $this->errorsCatalog['input']['invalid_input_data'], [
                'message' => 'Nothing to update',
            ]);
        }
        $clients[$token] = $row;
        $this->saveClients($apiId, $clients);
        HttpResp::json_out(200, ApiClients::publicClient($token, $row));
    }

    public function rotate($apiId, $token)
    {
        $this->requireApiAccess($apiId);
        try {
            $token = ApiClients::assertToken((string) $token);
        } catch (InvalidArgumentException $e) {
            $this->invalid($e);
        }
        $clients = $this->loadClients($apiId);
        if (!isset($clients[$token]) || !is_array($clients[$token])) {
            $this->missing($token);
        }
        $row = $clients[$token];
        $claims = is_array($row['claims'] ?? null) ? $row['claims'] : [];
        $validity = (int) ($row['validity'] ?? ApiClients::DEFAULT_VALIDITY);
        $issued = ApiClients::issue($claims, $validity, !empty($row['enabled']));
        $clients[$token] = $issued['row'];
        $this->saveClients($apiId, $clients);
        $out = ApiClients::publicClient($token, $issued['row']);
        $out['secret'] = $issued['secret'];
        HttpResp::json_out(200, $out);
    }

    public function remove($apiId, $token)
    {
        $this->requireApiAccess($apiId);
        try {
            $token = ApiClients::assertToken((string) $token);
        } catch (InvalidArgumentException $e) {
            $this->invalid($e);
        }
        $clients = $this->loadClients($apiId);
        if (!isset($clients[$token])) {
            $this->missing($token);
        }
        unset($clients[$token]);
        $this->saveClients($apiId, $clients);
        HttpResp::json_out(200, ['deleted' => true, 'token' => $token]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadClients(string $apiId): array
    {
        $data = $this->store->loadPhp($this->clientsPath($apiId));
        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, array<string, mixed>> $clients
     */
    private function saveClients(string $apiId, array $clients): void
    {
        $path = $this->clientsPath($apiId);
        if ($clients === []) {
            if (is_file($path)) {
                unlink($path);
            }
        } else {
            $this->store->savePhp($path, $clients);
        }
        $this->store->touchUpdated($apiId);
    }

    private function clientsPath(string $apiId): string
    {
        return $this->store->getApiDir($apiId) . '/' . ApiClients::FILE;
    }

    /**
     * @param mixed $claims
     * @return array<mixed>
     */
    private function claimsArray($claims): array
    {
        if (!is_array($claims)) {
            throw new InvalidArgumentException('claims must be an object');
        }
        return $claims;
    }

    private function invalid(InvalidArgumentException $e): void
    {
        $this->mgmtError(400, $this->errorsCatalog['input']['invalid_input_data'], [
            'message' => $e->getMessage(),
        ]);
    }

    private function missing(string $token): void
    {
        $this->mgmtError(404, ['code' => 4040, 'message' => 'API client not found'], ['token' => $token]);
    }
}
