<?php
/**
 * OpenAPI path operations and path parameters.
 */

$recursionLevel = 10;
/**
 * OpenAPI path parameter name unique per resource (avoids duplicate {id} in nested paths).
 */
function swagger_path_param_name(string $resourceName, string $keyFld): string
{
    return $resourceName . '_' . $keyFld;
}

/**
 * Path segment for a resource primary key, e.g. /teams/{teams_id}
 */
function swagger_single_resource_path(string $resourceName, array $resourceSpecifications): string
{
    $param = swagger_path_param_name($resourceName, $resourceSpecifications['keyFld']);
    return '/' . $resourceName . '/{' . $param . '}';
}

function swagger_path_parameter(
    string $resourceName,
    array $resourceSpecifications,
    ?string $paramName = null,
    ?string $description = null
): array {
    $keyFld = $resourceSpecifications['keyFld'];
    $name = $paramName ?? swagger_path_param_name($resourceName, $keyFld);
    return [
        'name' => $name,
        'in' => 'path',
        'description' => $description ?? "Primary key ({$keyFld}) of the {$resourceName} record",
        'required' => true,
        'schema' => ['type' => 'string'],
    ];
}

/**
 * @param array<int,array{0:string,1:array,2?:string,3?:string}> $pathContext
 */
function swagger_build_path_parameters(string $resourceName, array $resourceSpecifications, array $pathContext = []): array
{
    if (empty($pathContext)) {
        return [];
    }
    $params = [];
    foreach ($pathContext as $ctx) {
        $params[] = swagger_path_parameter($ctx[0], $ctx[1], $ctx[2] ?? null, $ctx[3] ?? null);
    }
    return $params;
}

function swagger_standard_error_responses(): array
{
    return [
        '400' => ['$ref' => '#/components/responses/BadRequest'],
        '401' => ['$ref' => '#/components/responses/NotAuthorized'],
        '403' => ['$ref' => '#/components/responses/Forbidden'],
        '404' => ['$ref' => '#/components/responses/NotFound'],
        '500' => ['$ref' => '#/components/responses/ServerError'],
    ];
}

/********************************
 * path: /
 ********************************/


/**
 * @param $resName
 * @param $resSpec
 * @param $path
 * @param $fields
 * @param $includes
 * @param $dm
 * @param $recursionLevel
 */
function search_recursive($resName,$resSpec,$path,&$fields,&$includes,&$dm,$recursionLevel)
{
    if($recursionLevel--<0)
        return;

    if(isset($fields[$resName]))
        return;

    foreach ($resSpec["fields"] as $fldName => $fldSpec) {
        if (!isset($fldSpec["select"]) || $fldSpec["select"])
            $fields[$resName][] = $fldName;
    }

    if(!isset($resSpec["relations"]))
        return;

    foreach ($resSpec["relations"] as $relName => $relSpec) {
        if (!isset($relSpec["select"]) || $relSpec["select"])
            $fields[$resName][] = $relName;
    }

    foreach ($resSpec["relations"] as $relName => $relSpec) {
        if (isset($relSpec["select"]) && $relSpec["select"]) {
            $newPath = array_merge($path,[$relName]);
            $newPathStr = implode(".",$newPath);
            if(!in_array($newPathStr,$includes)) {
                $includes[] = $newPathStr;
                search_recursive($relSpec["table"],$dm[$relSpec["table"]],$newPath,$fields,$includes,$dm,$recursionLevel);
            }
        }
    }

}


/**
 * @return array
 */
function bulk_create_mixed_records()
{
    // todo
    return  [
        "summary" => "Bulk create records",
        "description" => "Returns created records",
        "operationId"=>"create_multiple_records",
        "tags"=>["_bulk"],
        "parameters" => [
            [
                "name"=>"Content-type",
                "in"=>"header",
                "required"=>true,
                "schema"=>[
                    "type"=>"string",
                    "default"=>"application/json"
                ]
            ],
            [
                "name"=>"onduplicate",
                "in"=>"query",
                "description"=>"Select behaviour when a duplicate key conflict occurs. Possible options:\n- update: update certain fields \n- ignore: do nothing and \n ",
                "required"=>false,
                "schema"=>[
                    "\$ref"=> "#/components/schemas/onUpdatePara"
                ]
            ],
            [
                "name"=>"update",
                "in"=>"query",
                "description"=>"Comma separated list of fields to update when onduplicate=update. A field replaces the stored value. field:add and field:sub add or subtract the inserted value on numeric columns. field:set is the same as a bare field.",
                "required"=>false,
                "explode"=>false,
                "schema"=>[
                    "type"=>"string"
                ]
            ]
        ],
        "responses"=>[
            "201"=>[
                "description"=>"",
                "headers"=>[
                    "Location"=>[
                        "schema"=>[
                            "type"=>"string"
                        ]
                    ]
                ],
                "content"=>[
                    "application/json"=>[
                        "schema"=>[
                            "type"=>"array",
                            "items"=>[
                                "\$ref"=>"#/components/schemas/GenericResourceObject"
                            ]
                        ]
                    ]
                ]
            ],
            "403"=>[
                "description"=>""
            ],
            "409"=>[
                "description"=>""
            ],

        ],
        "requestBody"=>[
            "description"=>"Array of objects to be created as records",
            "content"=>[
                "application/json"=>[
                    "schema"=>[
                        "type"=>"object",
                        "properties"=>[
                            "data"=>[
                                "type"=>"array",
                                "items"=>[
                                    "\$ref"=>"#/components/schemas/GenericResourceObject"
                                ],
                                "minItems"=>1
                            ]
                        ],
                        "required"=>["data"]
                    ]
                ]
            ]
        ]
    ];
}


/**
 * @return array
 */
function update_multiple_records()
{
    // todo
    return [
        "summary" => "Bulk update records",
        "description" => "",
        "operationId" => "update_multiple_records",
        "tags"=>["_bulk"],
        "requestBody"=>[
            "description"=>"Bulk update records by providing an array of resource objects",
            "content"=>[
                "application/json"=>[
                    "schema"=>[
                        "type"=>"object",
                        "properties"=>[
                            "data"=>[
                                "type"=>"array",
                                "items"=>[
                                    "\$ref"=>"#/components/schemas/GenericResourceObject"
                                ],
                                "minItems"=>1

                            ]
                        ],
                        "required"=>["data"]
                    ]
                ]
            ]
        ],
        "parameters" => [
            [
                "name"=>"Content-type",
                "in"=>"header",
                "required"=>true,
                "schema"=>[
                    "type"=>"string",
                    "default"=>"application/json"
                ]
            ],

        ],
        "responses"=> [
            "200"=>[
                "description"=>"todo"
            ]
        ]
    ];
}

/**
 * @return array
 */
function delete_multiple_records()
{
    // todo
    return [
        "summary" => "Bulk delete records",
        "description" => "",
        "operationId" => "delete_multiple_records",
        "tags"=>["_bulk"],
        "requestBody"=>[
            "description"=>"Bulk delete records by providing an array of resource identifier objects",
            "content"=>[
                "application/json"=>[
                    "schema"=>[
                        "type"=>"object",
                        "properties"=>[
                            "data"=>[
                                "type"=>"array",
                                "items"=>[
                                    "\$ref"=>"#/components/schemas/GenericResourceIdentifierObject"
                                ],
                                "minItems"=>0

                            ]
                        ],
                        "required"=>["data"]
                    ]
                ]
            ]
        ],
        "parameters" => [],
        "responses"=> [
            "200"=>[
                "description"=>"todo"
            ]
        ]
    ];
}
/********************************
 * path: /resourceName
 ********************************/
/**
 * @param $resourceName
 * @param $resourceSpecifications
 * @param $dataModel
 * @return array
 */
function get_records($tags,$resourceName, $resourceSpecifications, $dataModel,$overwrites=[],array $pathContext=[])
{
    $data = [
        "summary" => "Get '$resourceName' records list",
        "description" => "",
        "operationId" => $resourceName."_get_multiple_records",
        "parameters" => [],
        "tags"=>$tags,
        "responses"=> array_merge(
            ["200"=>['$ref'=>"#/components/responses/{$resourceName}_GetRecords"]],
            swagger_standard_error_responses()
        ),
    ];

    $queryParams = [
        ['$ref'=>"#/components/parameters/{$resourceName}_filter"],
        ['$ref'=>"#/components/parameters/{$resourceName}_fields"],
        ['$ref'=>"#/components/parameters/{$resourceName}_limit"],
        ['$ref'=>"#/components/parameters/{$resourceName}_offset"],
        ['$ref'=>"#/components/parameters/{$resourceName}_sort"],
    ];
    if (!empty(resource_relations($resourceSpecifications))) {
        $queryParams[] = ['$ref'=>"#/components/parameters/{$resourceName}_include"];
    }

    $data["parameters"] = array_merge(
        swagger_build_path_parameters($resourceName, $resourceSpecifications, $pathContext),
        $queryParams
    );

    return mergeRecursive($data,$overwrites);
}

/**
 * @param $resourceName
 * @param $resourceSpecifications
 * @return array
 */
function create_records($tags,$resourceName, $resourceSpecifications,$datamodel,$overwrites=[],array $pathContext=[])
{
    $parameters = array_merge(
        swagger_build_path_parameters($resourceName, $resourceSpecifications, $pathContext),
        [
            ['$ref'=>"#/components/parameters/onduplicate"],
            ['$ref'=>"#/components/parameters/contentTypeJson"],
            ['$ref'=>"#/components/parameters/{$resourceName}_update"],
        ]
    );
    if (!empty(resource_relations($resourceSpecifications))) {
        $parameters[] = ['$ref'=>"#/components/parameters/{$resourceName}_include"];
    }
    $data = [
        "summary" => "Create records of type $resourceName",
        "description" => "This method allows both single record creation as well as batch record creation.\n"
            ."Accepts JSON:API (`application/json`) or CSV (`text/csv`, or `multipart/form-data` with a `file`/`csv` upload). "
            ."CSV header columns must be insertable attribute names; nested/relationship columns are not supported.\n"
            ."The create operation is enclosed in a transaction. If one of the inserts fail the entire block will fail.\n"
            ."For handling errors causes by duplicate inserts use the **onduplicate** parameter",
        "operationId" => $resourceName."_create_multiple_records",
        "parameters" => $parameters,
        "tags"=>$tags,
        "requestBody"=>['$ref'=>"#/components/requestBodies/{$resourceName}_Create"],
        "responses"=> [
            "201"=>['$ref'=>"#/components/responses/{$resourceName}_CreateOK"],
            "400"=>['$ref'=>"#/components/responses/BadRequest"],
            "401"=>['$ref'=>"#/components/responses/NotAuthorized"],
            "403"=>['$ref'=>"#/components/responses/Forbidden"],
            "404"=>['$ref'=>"#/components/responses/NotFound"],
            "409"=>['$ref'=>"#/components/responses/Conflict"],
            "500"=>['$ref'=>"#/components/responses/ServerError"],

        ]
    ];

    return array_merge_recursive($data,$overwrites);
}



/********************************
 * path: /resourceName/$id
 ********************************/
/**
 * @param $resourceName
 * @param $resourceSpecifications
 * @param $dataModel
 * @return array
 */
function get_record_by_id($tags,$resourceName, $resourceSpecifications, $dataModel,$overwrites=[],array $pathContext=[])
{
    $data = [
        "summary" => "Get '$resourceName' record by ID (primary key)",
        "description" => "get record by id",
        "operationId" => $resourceName."_get_record_by_id",
        "tags"=>$tags,
        "responses"=> array_merge(
            ["200"=>[ '$ref'=>"#/components/responses/{$resourceName}_GetRecordById"]],
            swagger_standard_error_responses()
        ),
    ];

    $queryParams = [
        ['$ref'=>"#/components/parameters/{$resourceName}_fields"],
    ];
    if (!empty(resource_relations($resourceSpecifications))) {
        $queryParams[] = ['$ref'=>"#/components/parameters/{$resourceName}_include"];
    }

    $data["parameters"] = array_merge(
        swagger_build_path_parameters($resourceName, $resourceSpecifications, $pathContext),
        $queryParams
    );

    return mergeRecursive($data,$overwrites);
}

/**
 * @param $resourceName
 * @param $resourceSpecifications
 * @param $dataModel
 * @return array
 */
function update_single_record($tags,$resourceName,$resourceSpecifications,$dataModel,$overwrites=[],array $pathContext=[])
{
    $data = [
        "summary" => "Update single record of type $resourceName",
        "description" => "",
        "tags"=>$tags,
        "operationId" => $resourceName."_update_single_record",
    ];
    $data["parameters"]= array_merge(
        swagger_build_path_parameters($resourceName, $resourceSpecifications, $pathContext),
        [['$ref'=>"#/components/parameters/onduplicate"]]
    );

    $data["requestBody"] = ['$ref'=>"#/components/requestBodies/{$resourceName}_Update"];

    $data["responses"] = array_merge(
        ["200"=>[ '$ref'=>"#/components/responses/{$resourceName}_GetRecordById"]],
        swagger_standard_error_responses()
    );
    return mergeRecursive($data,$overwrites);

}

/**
 * @param $resourceName
 * @param $resourceSpecifications
 * @param $dataModel
 * @return array
 */
function delete_single_record($tags,$resourceName,$resourceSpecifications,$dataModel,$overwrites=[],array $pathContext=[])
{
    $data = [
        "summary" => "Delete single record of type $resourceName",
        "description" => "",
        "tags"=>$tags,
        "operationId" => $resourceName."_delete_single_record",
        "parameters"=> swagger_build_path_parameters($resourceName, $resourceSpecifications, $pathContext),
        "responses"=> array_merge(
            ["204"=>[ '$ref'=>"#/components/responses/NoContent"]],
            swagger_standard_error_responses()
        ),
    ];
    return mergeRecursive($data,$overwrites);

}

/**
 * Outbound to-one relation: /parent/{parentId}/relationName (no related PK in path).
 */
function get_outbound_related_record(
    array $tags,
    string $parentResource,
    array $parentSpec,
    string $parentPathParam,
    string $relationName,
    array $relSpec,
    array $dataModel,
    array $overwrites = []
) {
    $relatedResource = $relSpec['table'];
    $relatedSpec = $dataModel[$relatedResource];
    $data = [
        'summary' => "Get related {$relationName} of {$parentResource}",
        'description' => "Returns the {$relatedResource} record linked from {$parentResource} via **{$relationName}** (resolved through the parent foreign key).",
        'operationId' => "{$parentResource}_get_related_{$relationName}",
        'tags' => $tags,
        'parameters' => array_merge(
            [swagger_path_parameter(
                $parentResource,
                $parentSpec,
                $parentPathParam,
                "Primary key ({$parentSpec['keyFld']}) of the parent {$parentResource} record"
            )],
            [
                ['$ref' => "#/components/parameters/{$relatedResource}_fields"],
            ],
            !empty(resource_relations($relatedSpec))
                ? [['$ref' => "#/components/parameters/{$relatedResource}_include"]]
                : []
        ),
        'responses' => array_merge(
            ['200' => ['$ref' => "#/components/responses/{$relatedResource}_GetRecordById"]],
            swagger_standard_error_responses()
        ),
    ];
    return mergeRecursive($data, $overwrites);
}

function update_outbound_related_record(
    array $tags,
    string $parentResource,
    array $parentSpec,
    string $parentPathParam,
    string $relationName,
    array $relSpec,
    array $dataModel,
    array $overwrites = []
) {
    $relatedResource = $relSpec['table'];
    $relatedSpec = $dataModel[$relatedResource];
    $data = [
        'summary' => "Update related {$relationName} of {$parentResource}",
        'description' => "Updates the linked {$relatedResource} record for the given {$parentResource} (via **{$relationName}**).",
        'operationId' => "{$parentResource}_update_related_{$relationName}",
        'tags' => $tags,
        'parameters' => array_merge(
            [swagger_path_parameter(
                $parentResource,
                $parentSpec,
                $parentPathParam,
                "Primary key ({$parentSpec['keyFld']}) of the parent {$parentResource} record"
            )],
            [['$ref' => '#/components/parameters/onduplicate']]
        ),
        'requestBody' => ['$ref' => "#/components/requestBodies/{$relatedResource}_Update"],
        'responses' => array_merge(
            ['200' => ['$ref' => "#/components/responses/{$relatedResource}_GetRecordById"]],
            swagger_standard_error_responses()
        ),
    ];
    return mergeRecursive($data, $overwrites);
}

/********************************
 * path: /resourceName/$id/__relationships/$relation
 ********************************/
/**
 * @param $resourceName
 * @param $resourceSpecifications
 * @param $dataModel
 * @return array
 */
function get_relationships($resourceName,$resourceSpecifications,$relationshipName)
{
    //echo  $resourceName.".".$relationshipName."\n";
    //print_r($resourceSpecifications["relations"]);

    $rel = $resourceSpecifications["relations"][$relationshipName];
    if($rel["type"]=="outbound")
        $dataObj = [
            "\$ref"=>"#/components/schemas/{$rel["table"]}_ResourceIdentifierObject"
        ];
    else
        $dataObj = [
            "type"=>"array",
            "items"=>[
                "\$ref"=>"#/components/schemas/{$rel["table"]}_ResourceIdentifierObject"
            ],
            "minItems"=>0,
            "uniqueItems"=>true
        ];

    $data = [
        "summary" => "Get '$relationshipName' relationship of type '$resourceName'",
        "description" => "Get $resourceName relationships of type $relationshipName",
        "operationId" => $resourceName."_get_relationship_".$relationshipName,
        "tags"=>[$resourceName."/relationship/".$relationshipName],
        "parameters" => [

        ],
        "responses"=> [
            "200"=>[
                "description"=> "Return record of type $resourceName identified by ID",
                "content"=>[
                    "application/json"=>[
                        "schema"=> [
                            "type"=> "object",
                            "properties"=> [
                                "data"=> $dataObj
                            ],
                            "required"=>["data"]
                        ]
                    ]
                ]
            ],
            "404"=>[
                "description"=>"Not found"
            ]
        ]
    ];

    $data["parameters"]= [
        [
            "name"=>$resourceSpecifications["keyFld"],
            "in"=>"path",
            "description"=>"Field which uniquely identifies the retrieved record",
            "required"=>true,
            "schema"=>[
                "type"=>"string"
            ]
        ]
    ];
    return $data;
}

/**
 * @param $resourceName
 * @param $resourceSpecifications
 * @param $dataModel
 * @return array
 */
function create_relationships($resourceName,$resourceSpecifications,$relationshipName)
{
    $data = get_relationships($resourceName,$resourceSpecifications,$relationshipName);
    // todo
    $data["summary"] = "Create single or multiple $resourceName relationships of type $relationshipName";
    $data["description"] = "";
    $data["operationId"] = $resourceName."_create_relationship_".$relationshipName;
    return $data;
}

/**
 * @param $resourceName
 * @param $resourceSpecifications
 * @param $dataModel
 * @return array
 */
function update_relationships($resourceName,$resourceSpecifications,$relationshipName)
{
    // todo
    $data = get_relationships($resourceName,$resourceSpecifications,$relationshipName);
    $data["summary"] = "Update single or multiple $resourceName relationships of type $relationshipName";
    $data["description"] = "";
    $data["operationId"] = $resourceName."_update_relationship_".$relationshipName;

    return $data;
}

/**
 * @param $resourceName
 * @param $resourceSpecifications
 * @param $dataModel
 * @return array
 */
function delete_relationships($resourceName,$resourceSpecifications,$relationshipName)
{

    $data = get_relationships($resourceName,$resourceSpecifications,$relationshipName);
    // todo
    $data["summary"] = "Delete single or multiple $resourceName relationships of type $relationshipName";
    $data["description"] = "";
    $data["operationId"] = $resourceName."_delete_relationship_".$relationshipName;

    return $data;

}

/********************************
 * path: /resourceName/$id/$relation
 ********************************/
/**
 * @param $resourceName
 * @param $resourceSpecification
 * @param $dataModel
 * @return array
 */
function get_related($resourceName, $resourceSpecification, $relationshipName)
{
    //return ["aaaaa"];
    // todo
    $relSpec = $resourceSpecification["relations"][$relationshipName];
    if($relSpec["type"]=="outbound")
        $dataObj = [
            "\$ref"=>"#/components/schemas/{$relSpec["table"]}_ResourceObject"
        ];
    else
        $dataObj = [
            "type"=>"array",
            "items"=>[
                "\$ref"=>"#/components/schemas/{$relSpec["table"]}_ResourceObject"
            ]
        ];

    $data = [
        "summary" => "Get related records of type $relationshipName for single record of type $resourceName",
        "tags"=>["$relationshipName/$resourceName"],
        "description" => "",
        "operationId" => $resourceName."_get_related_records_$relationshipName",
        "parameters" => [],
        "responses"=> [
            "200"=>[
                "description"=>"Returns related resource objects of type $relationshipName for $resourceName",
                "content"=>[
                    "application/json"=>[
                        "schema"=>[
                            "type"=>"object",
                            "properties"=>[
                                "data"=>$dataObj
                            ],
                            "required"=>["data"]
                        ]
                    ]
                ]

            ]
        ],

    ];

    $data["parameters"]= [
        [
            "name"=>$resourceSpecification["keyFld"],
            "in"=>"path",
            "description"=>"Field which uniquely identifies the retrieved record",
            "required"=>true,
            "schema"=>[
                "type"=>"string"
            ]
        ]
    ];
    return $data;
}
function mergeRecursive(array $a, array $b): array {
    foreach ($b as $key => $value) {
        if (array_key_exists($key, $a) && is_array($a[$key]) && is_array($value)) {
            $a[$key] = mergeRecursive($a[$key], $value);
        } else {
            $a[$key] = $value;
        }
    }
    return $a;
}
