<?php
defined('BASEPATH') OR exit('No direct script access allowed');

$route['^management-openapi\.json$']['get'] = 'swagger/management_json';
$route['^management-openapi-multi\.json$']['get'] = 'swagger/management_json/multi';
$route['^management-openapi-single\.json$']['get'] = 'swagger/management_json/single';
$route['^management-openapi\.yaml$']['get'] = 'swagger/management_yaml';
$route['^management-openapi-multi\.yaml$']['get'] = 'swagger/management_yaml/multi';
$route['^management-openapi-single\.yaml$']['get'] = 'swagger/management_yaml/single';

$mgmt = '^mgmt/v1/apis';
$mgmtApi = $mgmt . '/(:any)';

// Collection
$route[$mgmt]['get'] = 'mgmt/apis/list_apis';
$route[$mgmt]['post'] = 'mgmt/apis/create';

// Lifecycle colon actions (before generic apiId routes)
$route[$mgmtApi . ':validate']['post'] = 'mgmt/lifecycle/validate/$1';
$route[$mgmtApi . ':activate']['post'] = 'mgmt/lifecycle/activate/$1';
$route[$mgmtApi . ':deactivate']['post'] = 'mgmt/lifecycle/deactivate/$1';

// Credentials
$route[$mgmtApi . '/management-credentials:rotate']['post'] = 'mgmt/credentials/rotate/$1';

// Connection
$route[$mgmtApi . '/connection']['get'] = 'mgmt/connection/get/$1';
$route[$mgmtApi . '/connection']['put'] = 'mgmt/connection/update/$1';
$route[$mgmtApi . '/connection:test']['post'] = 'mgmt/connection/test/$1';

// Policies
$route[$mgmtApi . '/policies/config-network']['get'] = 'mgmt/policies/network/get/$1';
$route[$mgmtApi . '/policies/config-network']['put'] = 'mgmt/policies/network/update/$1';
$route[$mgmtApi . '/policies/data-network']['get'] = 'mgmt/policies/data/get/$1';
$route[$mgmtApi . '/policies/data-network']['put'] = 'mgmt/policies/data/update/$1';
$route[$mgmtApi . '/policies/auth']['get'] = 'mgmt/policies/auth/get/$1';
$route[$mgmtApi . '/policies/auth']['put'] = 'mgmt/policies/auth/update/$1';

$route[$mgmtApi . '/clients/(:any):rotate']['post'] = 'mgmt/clients/rotate/$1/$2';
$route[$mgmtApi . '/clients']['get'] = 'mgmt/clients/index/$1';
$route[$mgmtApi . '/clients']['post'] = 'mgmt/clients/create/$1';
$route[$mgmtApi . '/clients/(:any)']['patch'] = 'mgmt/clients/update/$1/$2';
$route[$mgmtApi . '/clients/(:any)']['delete'] = 'mgmt/clients/remove/$1/$2';

// Schema
$route[$mgmtApi . '/schema:introspect']['post'] = 'mgmt/schema/introspect/$1';
$route[$mgmtApi . '/schema:sync']['post'] = 'mgmt/schema/sync/$1';
$route[$mgmtApi . '/schema/introspected']['get'] = 'mgmt/schema/get_introspected/$1';
$route[$mgmtApi . '/schema/overrides']['get'] = 'mgmt/schema/get_overrides/$1';
$route[$mgmtApi . '/schema/overrides']['put'] = 'mgmt/schema/put_overrides/$1';
$route[$mgmtApi . '/schema/overrides']['patch'] = 'mgmt/schema/patch_overrides/$1';
$route[$mgmtApi . '/schema/effective']['get'] = 'mgmt/schema/get_effective/$1';
$route[$mgmtApi . '/schema:rebuild']['post'] = 'mgmt/schema/rebuild/$1';
$route[$mgmtApi . '/schema:regenerate-openapi']['post'] = 'mgmt/schema/regenerate_openapi/$1';
$route[$mgmtApi . '/schema/openapi']['get'] = 'mgmt/schema/get_openapi/$1';

// Hooks
$route[$mgmtApi . '/hooks']['get'] = 'mgmt/hooks/list_all/$1';
$route[$mgmtApi . '/hooks']['put'] = 'mgmt/hooks/replace_all/$1';
$route[$mgmtApi . '/hooks/(:any)']['put'] = 'mgmt/hooks/upsert_entity/$1/$2';
$route[$mgmtApi . '/hooks/(:any)']['delete'] = 'mgmt/hooks/delete_entity/$1/$2';

// Single API
$route[$mgmtApi]['get'] = 'mgmt/apis/get/$1';
$route[$mgmtApi]['patch'] = 'mgmt/apis/patch/$1';
$route[$mgmtApi]['delete'] = 'mgmt/apis/delete/$1';

// Legacy config API shim
$route['^apis']['post'] = 'mgmt/legacy/create_api';
