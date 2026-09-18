<?php
defined('BASEPATH') OR exit('No direct script access allowed');

use dbAPI\API\DBAPIRequest;

/**
 * @property CI_Config $config
 * @property CI_Loader $load
 * @property CI_Input $input
 * @property Utilities $utilities
 */
trait DbapiQueryTrait
{
    function process_query_parameters(string $fqResName, &$inputs=null, int $includeDepth = 0) {
        if ($includeDepth > ApiSafety::maxIncludeDepth()) {
            throw new \dbAPI\API\Exception(
                'Include depth exceeds maximum of ' . ApiSafety::maxIncludeDepth(),
                400
            );
        }

        $tmp = explode("/",$fqResName);
        $simpleName = array_pop($tmp);

        $request = new DBAPIRequest($simpleName, ApiSafety::limit('default_page_size'));

        // fetch include
        set_para("include", $inputs,$fqResName, $request);
        if($request->include) {
            $tree = parse_include_tree($request->include);
            $request->include = [];
            foreach ($tree as $relName => $nested) {
                if ($nested) {
                    merge_include_for_resource(
                        $inputs,
                        "$fqResName/$relName",
                        flatten_include_paths($nested)
                    );
                }
                $request->include[$relName] = $this->process_query_parameters(
                    "$fqResName/$relName",
                    $inputs,
                    $includeDepth + 1
                );
            }
        }

        // fetch filter and validate
        set_para("filter",$inputs,$fqResName, $request,$inputs);
        if(is_string($request->filter)) {
            $request->set_filter_from_string($request->filter);
        }

        set_para("fields",$inputs,$fqResName, $request,$inputs);
        if(is_string($request->fields)) {
            $request->fields = explode(",",$request->fields);
        }

        if(isset($inputs["page"])) {
            $pageSpec = $inputs["page"][$fqResName] ?? null;
            if (!is_array($pageSpec)) {
                $slash = strrpos($fqResName, '/');
                if ($slash !== false) {
                    $simple = substr($fqResName, $slash + 1);
                    if ($simple !== '' && isset($inputs["page"][$simple]) && is_array($inputs["page"][$simple])) {
                        $pageSpec = $inputs["page"][$simple];
                    }
                }
            }
            if (is_array($pageSpec)) {
                if(isset($pageSpec["offset"])) {
                    if(is_numeric($pageSpec["offset"])) {
                        $request->offset = ApiSafety::clampPageOffset((int) $pageSpec["offset"]);
                    } else {
                        throw new \dbAPI\API\Exception(
                            "Invalid page offset value for resource $request->resourceName",
                            400
                        );
                    }
                }
                if(isset($pageSpec["limit"])) {
                    if(is_numeric($pageSpec["limit"])) {
                        $request->limit = ApiSafety::clampPageLimit(
                            (int) $pageSpec["limit"],
                            ApiSafety::limit('default_page_size')
                        );
                    } else {
                        throw new \dbAPI\API\Exception(
                            "Invalid page limit value for resource $request->resourceName",
                            400
                        );
                    }
                }
            }
        }

        if (!isset($request->limit) || $request->limit <= 0) {
            $request->limit = ApiSafety::limit('default_page_size');
        } else {
            $request->limit = ApiSafety::clampPageLimit((int) $request->limit, ApiSafety::limit('default_page_size'));
        }
        $request->offset = ApiSafety::clampPageOffset((int) ($request->offset ?? 0));
        $request->page['limit'] = $request->limit;
        $request->page['offset'] = $request->offset;

        set_para("sort",$inputs,$fqResName, $request,$inputs);
        if(is_string($request->sort)) {
            $request->sort = explode(",",$request->sort);
        }

        set_para("filter_advanced",$inputs,$fqResName, $request,$inputs);
        if (is_string($request->filter_advanced) && $request->filter_advanced !== '') {
            \dbAPI\API\FilterParser::guardExpression($request->filter_advanced);
        }

        set_para("update", $inputs,$fqResName, $request,);
        if(is_string($request->update)) {
            $request->update = explode(",",$request->update);
        }

        $request->insertignore = ($inputs['insertignore'] ?? null) === 'true';

        if($request->update) {
            $request->onduplicate = "update";
        }
        else {
            set_para("onduplicate", $inputs, $fqResName,$request, function($value) {return in_array($value,["ignore","error","update"]);});
        }

        return $request;

    }

    /**
     * @param DBAPIRequest $request
     * @param DBAPIRequest|null $parentRequest
     * @param string|null $relName
     * @return DBAPIRequest
     * @throws \dbAPI\API\Exception
     */
    function attach_configuration_2_request(DBAPIRequest $request, DBAPIRequest $parentRequest=null, string $relName=null, array &$inputs=null) {

        if(is_null($parentRequest)) {
            // for top level resource check if is valid resource
//            $request->config = $this->apiDm->get_config($request->resourceName);
            if(!$this->apiDm->is_valid_resource($request->resourceName)) {
                throw new \dbAPI\API\Exception("Resource not found $request->resourceName",404);
            }
        }
        else {
            // for included resource get valid relationship
            $rel = $this->apiDm->get_relationship($parentRequest->resourceName, $relName);
            $request->resourceName = $rel["table"];
            $request->relSpec = $rel;
            $request->relName = $relName;
            // JSON:API fields[{type}] when path/rel-name keys did not match (e.g. customer_id → customers)
            $this->apply_type_keyed_query_params($request, $inputs);
        }


        // validate fields
        foreach ($request->fields as $fld) {
            if(!$this->apiDm->is_valid_field($request->resourceName,$fld)) {
                throw new \dbAPI\API\Exception("Invalid field $fld of $request->resourceName for sparse field selection",404);
            }
            if(!$this->apiDm->field_is_selectable($request->resourceName,$fld)) {
                throw new \dbAPI\API\Exception("Field $fld of $request->resourceName is not available for selection",404);
            }
        }

        $userFields = array_values(array_filter(
            is_array($request->fields) ? $request->fields : [],
            static function ($fld) {
                return $fld !== null && $fld !== '';
            }
        ));

        if (!count($userFields)) {
            $request->exportFields = $this->apiDm->get_selectable_fields($request->resourceName);
            $request->fields = $request->exportFields;
        } else {
            $request->exportFields = $userFields;
            $request->fields = $userFields;
        }

        $request->primaryKey = $this->apiDm->get_primary_key($request->resourceName);
        if ($this->apiDm->has_primary_key($request->resourceName)) {
            if (!in_array($request->primaryKey, $request->fields, true)) {
                $request->fields[] = $request->primaryKey;
            }
        }

        // Sparse fieldsets apply to attributes. Outbound FKs must stay selected so
        // relationship linkages (and include hydration) still appear in the document.
        foreach ($this->apiDm->get_all_relations($request->resourceName) as $relName => $relSpec) {
            if (($relSpec['type'] ?? '') !== 'outbound') {
                continue;
            }
            if (in_array($relName, $request->fields, true)) {
                continue;
            }
            if (!$this->apiDm->is_valid_field($request->resourceName, $relName)) {
                continue;
            }
            if (!$this->apiDm->field_is_selectable($request->resourceName, $relName)) {
                continue;
            }
            $request->fields[] = $relName;
        }

        $request->fields = array_values(array_filter(
            $request->fields,
            static function ($fld) {
                return $fld !== null && $fld !== '';
            }
        ));

        foreach ($request->fields as $idx => $fldName) {
            $request->fieldsIndexes[$fldName] = $idx;
        }

        foreach ($request->sort as $key=>$sortOption) {
            if(!preg_match("/^(\-?)(\w+)$/",$sortOption,$matches)) {
                throw new \dbAPI\API\Exception("Invalid ordering expresion $sortOption of $request->resourceName to be used for ordering the results",404);
            }

            if(!$this->apiDm->is_valid_field($request->resourceName,$matches[2])) {
                throw new \dbAPI\API\Exception("Invalid field $sortOption of $request->resourceName to be used for ordering the results",404);
            }
            $request->sort[$key] = "`{$matches[2]}`".($matches[1] ? " DESC" : "");
        }

        foreach ($request->include as $inclName=>$inclReq) {
            $rel = $this->attach_configuration_2_request($inclReq,$request,$inclName, $inputs);
            if(is_null($rel))
                throw new \dbAPI\API\Exception("Relationship $inclName of $request->resourceName not found",404);
            else
                $request->include[$inclName] = $rel;
        }

        return $request;
    }

    /**
     * Apply leftover fields[{type}] / sort[{type}] after an include resolves to its table name.
     */
    private function apply_type_keyed_query_params(DBAPIRequest $request, ?array &$inputs): void
    {
        if ($inputs === null) {
            return;
        }
        $type = $request->resourceName;
        if (!count($request->fields) && isset($inputs['fields'][$type])) {
            $f = $inputs['fields'][$type];
            $request->fields = is_string($f) ? explode(',', $f) : $f;
        }
        if ((!is_array($request->sort) || !count($request->sort)) && isset($inputs['sort'][$type])) {
            $s = $inputs['sort'][$type];
            $request->sort = is_string($s) ? explode(',', $s) : $s;
        }
    }

    /**
     * @param string $resourceName
     * @return DBAPIRequest
     * @throws \dbAPI\API\Exception
     */
    private function get_dbapi_request(string $resourceName) {
        $inputs = $this->input->get();

        foreach (["filter","sort","includes","page","onduplicate","update","fields","insertignore"] as $param) {
            if(!isset($inputs[$param]))
                continue;
            if(empty($inputs[$param]))
                continue;
            if(!is_array($inputs[$param])){
                $inputs[$param] = [
                    $resourceName => $inputs[$param]
                ];
            }
        }
        $request = $this->process_query_parameters($resourceName,$inputs);
        $request = $this->attach_configuration_2_request($request, null, null, $inputs);

        return $request;
        // resolve resourceNames to table names (for relations)

    }


    /**
     * get records from table or from view identified by $resourceName
     * @param string $configName
     * @param string $resourceName
     * @param string|null $recId
     * @param DBAPIRequest|null $request
     * @param bool $internal
     * @return RecordSet|null
     * @throws \dbAPI\API\Exception
     */
}
