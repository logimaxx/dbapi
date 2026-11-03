<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once APPPATH . 'helpers/deployment_helper.php';

$apiId = default_api_id();
$mgmt = '^mgmt/v1';

// Single API resource at GET/PATCH /mgmt/v1 (no collection endpoints in single mode)
$route[$mgmt]['get'] = "mgmt/apis/get/{$apiId}";
$route[$mgmt]['patch'] = "mgmt/apis/patch/{$apiId}";

// Lifecycle colon actions
$route[$mgmt . ':validate']['post'] = "mgmt/lifecycle/validate/{$apiId}";
$route[$mgmt . ':activate']['post'] = "mgmt/lifecycle/activate/{$apiId}";
$route[$mgmt . ':deactivate']['post'] = "mgmt/lifecycle/deactivate/{$apiId}";

// Credentials
$route[$mgmt . '/management-credentials:rotate']['post'] = "mgmt/credentials/rotate/{$apiId}";

// Connection
$route[$mgmt . '/connection']['get'] = "mgmt/connection/get/{$apiId}";
$route[$mgmt . '/connection']['put'] = "mgmt/connection/update/{$apiId}";
$route[$mgmt . '/connection:test']['post'] = "mgmt/connection/test/{$apiId}";

// Policies
$route[$mgmt . '/policies/config-network']['get'] = "mgmt/policies/network/get/{$apiId}";
$route[$mgmt . '/policies/config-network']['put'] = "mgmt/policies/network/update/{$apiId}";
$route[$mgmt . '/policies/data-network']['get'] = "mgmt/policies/data/get/{$apiId}";
$route[$mgmt . '/policies/data-network']['put'] = "mgmt/policies/data/update/{$apiId}";
$route[$mgmt . '/policies/auth']['get'] = "mgmt/policies/auth/get/{$apiId}";
$route[$mgmt . '/policies/auth']['put'] = "mgmt/policies/auth/update/{$apiId}";

$route[$mgmt . '/clients/(:any):rotate']['post'] = "mgmt/clients/rotate/{$apiId}/$1";
$route[$mgmt . '/clients']['get'] = "mgmt/clients/index/{$apiId}";
$route[$mgmt . '/clients']['post'] = "mgmt/clients/create/{$apiId}";
$route[$mgmt . '/clients/(:any)']['patch'] = "mgmt/clients/update/{$apiId}/$1";
$route[$mgmt . '/clients/(:any)']['delete'] = "mgmt/clients/remove/{$apiId}/$1";

// Schema
$route[$mgmt . '/schema:introspect']['post'] = "mgmt/schema/introspect/{$apiId}";
$route[$mgmt . '/schema:sync']['post'] = "mgmt/schema/sync/{$apiId}";
$route[$mgmt . '/schema/introspected']['get'] = "mgmt/schema/get_introspected/{$apiId}";
$route[$mgmt . '/schema/overrides']['get'] = "mgmt/schema/get_overrides/{$apiId}";
$route[$mgmt . '/schema/overrides']['put'] = "mgmt/schema/put_overrides/{$apiId}";
$route[$mgmt . '/schema/overrides']['patch'] = "mgmt/schema/patch_overrides/{$apiId}";
$route[$mgmt . '/schema/effective']['get'] = "mgmt/schema/get_effective/{$apiId}";
$route[$mgmt . '/schema:rebuild']['post'] = "mgmt/schema/rebuild/{$apiId}";
$route[$mgmt . '/schema:regenerate-openapi']['post'] = "mgmt/schema/regenerate_openapi/{$apiId}";
$route[$mgmt . '/schema/openapi']['get'] = "mgmt/schema/get_openapi/{$apiId}";

// Hooks
$route[$mgmt . '/hooks']['get'] = "mgmt/hooks/list_all/{$apiId}";
$route[$mgmt . '/hooks']['put'] = "mgmt/hooks/replace_all/{$apiId}";
$route[$mgmt . '/hooks/(:any)']['put'] = "mgmt/hooks/upsert_entity/{$apiId}/$1";
$route[$mgmt . '/hooks/(:any)']['delete'] = "mgmt/hooks/delete_entity/{$apiId}/$1";
