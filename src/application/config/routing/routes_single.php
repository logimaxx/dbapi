<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once APPPATH . 'helpers/deployment_helper.php';

$singleApiId = default_api_id();
$dataApiPrefix = '^v1/data';
$basePath = '^v1';
$apiAuthUrlPrefix = '^v1/auth';

// Stored procedures
$route[$dataApiPrefix . '/__call__/(:any)$']['post'] = "dbapi/call_stored_procedure/{$singleApiId}/$1";

// OPTIONS
$route[$basePath . '/.*']['options'] = 'dbapi/options';

// OpenAPI spec
$route[$basePath . '/swagger']['get'] = "swagger/index/{$singleApiId}";
// $route['^swagger']['get'] = "swagger/index/{$singleApiId}";

// Auth
$route[$apiAuthUrlPrefix . '/login']['get'] = "auth/login_methods/{$singleApiId}";
$route[$apiAuthUrlPrefix . '/login/(:any)']['post'] = "auth/login/{$singleApiId}/$1";
$route[$apiAuthUrlPrefix . '/session']['get'] = "auth/session/{$singleApiId}";
$route[$apiAuthUrlPrefix . '/refresh']['post'] = "auth/refresh/{$singleApiId}";
$route[$apiAuthUrlPrefix . '/logout']['post'] = "auth/logout/{$singleApiId}";
$route[$apiAuthUrlPrefix . '/client']['post'] = "auth/client/{$singleApiId}";
$route[$apiAuthUrlPrefix . '/verify']['post'] = "auth/mfa_code_verify/{$singleApiId}";
$route[$apiAuthUrlPrefix . '/apiclienttoken/(.*)/(\d+)']['get'] = "auth/genApiClientToken/{$singleApiId}/$1/$2";

// Table level
$route[$dataApiPrefix . '/(:any)']['get'] = "dbapi/get_records/{$singleApiId}/$1";
$route[$dataApiPrefix . '/(:any)']['post'] = "dbapi/create_records/{$singleApiId}/$1";
$route[$dataApiPrefix . '/(:any)']['patch'] = "dbapi/bulk_update/{$singleApiId}/$1";
$route[$dataApiPrefix . '/(:any)']['delete'] = "dbapi/bulk_delete/{$singleApiId}/$1";

// Record level
$route[$dataApiPrefix . '/(:any)/(:any)']['get'] = "dbapi/get_records/{$singleApiId}/$1/$2";
$route[$dataApiPrefix . '/(:any)/(:any)']['patch'] = "dbapi/update_single_record/{$singleApiId}/$1/$2";
$route[$dataApiPrefix . '/(:any)/(:any)']['delete'] = "dbapi/delete_single_record/{$singleApiId}/$1/$2";

// Relation level
$route[$dataApiPrefix . '/(:any)/(:any)/(:any)']['get'] = "dbapi/get_related/{$singleApiId}/$1/$2/$3";
$route[$dataApiPrefix . '/(:any)/(:any)/(:any)']['post'] = "dbapi/create_related/{$singleApiId}/$1/$2/$3";
$route[$dataApiPrefix . '/(:any)/(:any)/(:any)']['patch'] = "dbapi/update_related/{$singleApiId}/$1/$2/$3";

// Related record level
$route[$dataApiPrefix . '/(:any)/(:any)/(:any)/(:any)']['get'] = "dbapi/get_related/{$singleApiId}/$1/$2/$3/$4";
$route[$dataApiPrefix . '/(:any)/(:any)/(:any)/(:any)']['patch'] = "dbapi/update_related/{$singleApiId}/$1/$2/$3/$4";
$route[$dataApiPrefix . '/(:any)/(:any)/(:any)/(:any)']['delete'] = "dbapi/delete_related/{$singleApiId}/$1/$2/$3/$4";

// Sub-relation level
$route[$dataApiPrefix . '/(:any)/(:any)/(:any)/(:any)/(:any)']['get'] = "dbapi/get_related_2nd/{$singleApiId}/$1/$2/$3/$4/$5";
$route[$dataApiPrefix . '/(:any)/(:any)/(:any)/(:any)/(:any)']['post'] = "dbapi/create_related_2nd/{$singleApiId}/$1/$2/$3/$4/$5";
$route[$dataApiPrefix . '/(:any)/(:any)/(:any)/(:any)/(:any)']['patch'] = "dbapi/update_related_2nd/{$singleApiId}/$1/$2/$3/$4/$5";

// Sub-related record level
$route[$dataApiPrefix . '/(:any)/(:any)/(:any)/(:any)/(:any)/(:any)']['get'] = "dbapi/get_related_2nd/{$singleApiId}/$1/$2/$3/$4/$5/$6";
$route[$dataApiPrefix . '/(:any)/(:any)/(:any)/(:any)/(:any)/(:any)']['patch'] = "dbapi/update_related_2nd/{$singleApiId}/$1/$2/$3/$4/$5/$6";
$route[$dataApiPrefix . '/(:any)/(:any)/(:any)/(:any)/(:any)/(:any)']['delete'] = "dbapi/delete_related_2nd/{$singleApiId}/$1/$2/$3/$4/$5/$6";
