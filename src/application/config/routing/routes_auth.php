<?php
defined('BASEPATH') OR exit('No direct script access allowed');

$apiAuthUrlPrefix = "^apis/(:any)/auth";
$route[$apiAuthUrlPrefix."/login"]["get"] = "auth/login_methods/$1";
$route[$apiAuthUrlPrefix."/login/(:any)"]["post"] = "auth/login/$1/$2";
$route[$apiAuthUrlPrefix."/session"]["get"] = "auth/session/$1";
$route[$apiAuthUrlPrefix."/refresh"]["post"] = "auth/refresh/$1";
$route[$apiAuthUrlPrefix."/logout"]["post"] = "auth/logout/$1";
$route[$apiAuthUrlPrefix."/client"]["post"] = "auth/client/$1";
$route[$apiAuthUrlPrefix."/verify"]["post"] = "auth/mfa_code_verify/$1";
$route[$apiAuthUrlPrefix."/apiclienttoken/(.*)/(\d+)"]["get"] = "auth/genApiClientToken/$1/$2/$3";
