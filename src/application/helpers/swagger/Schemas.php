<?php
/**
 * OpenAPI component schemas, request/response bodies, and reusable parameters.
 */

/**
 * Relation map from resource spec (empty when omitted).
 */
function resource_relations(array $resourceSpecifications): array
{
    return $resourceSpecifications['relations'] ?? [];
}

/**
 * True when a relation points at an entity present in the effective schema.
 */
function swagger_relation_target_exists(array $relSpec, array $dataModel): bool
{
    $table = $relSpec['table'] ?? '';
    return $table !== '' && isset($dataModel[$table]);
}

/**
 * Fields with a DB type — skips patch-only permission stubs (e.g. orphan hiddenFields).
 */
function swagger_typed_fields(array $resourceSpecifications): array
{
    $fields = [];
    foreach ($resourceSpecifications['fields'] ?? [] as $fldName => $fldSpec) {
        if (!is_array($fldSpec) || !isset($fldSpec['type']) || !is_array($fldSpec['type'])) {
            continue;
        }
        $fields[$fldName] = $fldSpec;
    }
    return $fields;
}
/**
 * create 3 schema components to be used as references along the spec:
 * - resourceObject - specifies the structure of a JSONAPI Resource Object (https://jsonapi.org/format/#document-resource-objects) as when originating on the server
 * - newResourceObject - specifies the structure of a JSONAPI Resource Object as when originating from client (eg: in case of create). In this case the field ID is not required
 * - resourceIdentifierObject - specifies the structure of a JSONAPI Resourcer Identifier Object (https://jsonapi.org/format/#document-resource-identifier-objects)
 * @param $resourceName
 * @param $resourceSpecification
 * @param array $dataModel effective schema (optional; skips orphan relation refs when set)
 * @return array
 */
function add_components($resourceName, $resourceSpecification, array $dataModel = [])
{
    $baseSchema = [
        "type"=>"object",
        "properties"=>[
            "id"=>[
                "type"=>"string"
            ],
            "type"=>[
                "type"=>"string",
                "enum"=>[$resourceName]
            ]
        ],
        "required"=>["type"]
    ];


    if(!empty($resourceSpecification["keyFld"]))
        $baseSchema["required"][] = "id";
    $resourceSchema = $baseSchema;
    $resourceSchema["properties"]["attributes"] = [
        "type"=>"object",
        "properties"=>[]
    ];
    $resourceSchema["required"][] = "attributes";

    // extract attributes
    $reqAttrs = [];
    foreach (swagger_typed_fields($resourceSpecification) as $fldName => $fldSpec) {
        $resourceSchema["properties"]["attributes"]["properties"][$fldName] = typeMap($fldSpec['type']);
        if (!empty($fldSpec['required'])) {
            $reqAttrs[] = $fldName;
        }
    }
    if(count($reqAttrs))
        $resourceSchema["properties"]["attributes"]["required"] = $reqAttrs;

    // extract relationships (optional in responses; not marked required)
    if(isset($resourceSpecification["relations"])) {
        $resourceSchema["properties"]["relationships"] = [
            "type"=>"object",
            "properties"=>[]
        ];
        foreach ($resourceSpecification["relations"] as $relName=>$relSpec) {
            if ($dataModel !== [] && !swagger_relation_target_exists($relSpec, $dataModel)) {
                continue;
            }
            if($relSpec["type"]=="inbound")
                $resourceSchema["properties"]["relationships"]["properties"][$relName] = [
                    "type"=>"object",
                    "properties"=>[
                        "data"=>[
                            "type"=>"array",
                            "items"=>[
                                "\$ref"=>"#/components/schemas/".$relSpec["table"]."_ResourceIdentifierObject"
                            ]
                        ]
                    ],
                    "required"=>["data"]
                ];
            else
                $resourceSchema["properties"]["relationships"]["properties"][$relName] = [
                    "type"=>"object",
                    "properties"=>[
                        "data"=>[
                            "\$ref"=>"#/components/schemas/".$relSpec["table"]."_ResourceIdentifierObject"
                        ]
                    ],
                    "required"=>["data"]
                ];

        }
    }

    $createResourceSchema = $resourceSchema;
    unset($createResourceSchema["required"]);
    return [
        "{$resourceName}_ResourceIdentifierObject"=>$baseSchema,
        "{$resourceName}_ResourceObject"=>$resourceSchema,
        "{$resourceName}_ResourceObjectCreate"=>$createResourceSchema,
    ];

}

/**
 * @param $typeSpec
 * @return array
 */
function typeMap($typeSpec)
{
    if (!is_array($typeSpec)) {
        return ['type' => 'string', 'description' => 'Unknown field type'];
    }

    $mysqlTypes = [
        "int"=>[
            "type"=>"integer"
        ],
        "varchar"=>[
            "type"=>"string"
        ],
        "enum"=> [
            "type"=>"string",
        ],
        "set"=> [
            "type"=>"string",
        ],
        "date"=>[
            "type"=>"string"
        ],
        "tinyint"=>[
            "type"=>"boolean"
        ],
        "datetime"=>[
            "type"=>"string"
        ],
        "float"=>[
            "type"=>"number"
        ],
        "double"=>[
            "type"=>"number"
        ],
        "bigint"=>[
            "type"=>"integer"
        ],
        "text"=>[
            "type"=>"string"
        ],
        "decimal"=>[
            "type"=>"number"
        ],
        "tinytext"=>[
            "type"=>"string"
        ],
        "timestamp"=>[
            "type"=>"string"
        ],
        "time"=>[
            "type"=>"string"
        ],
        "bit"=>[
            "type"=>"string"
        ]

    ];
    $proto = $typeSpec['proto'] ?? 'unknown';
    $res = isset($mysqlTypes[$proto]) ? $mysqlTypes[$proto] : ['type' => 'string', 'description' => 'MySQL type ' . $proto];
    if (in_array($proto, ['enum', 'set'], true)) {
        $res['enum'] = $typeSpec['vals'] ?? [];
    }
    return $res;
}
function create_request_body_create($resourceName){
    return [
        "required"=>true,
        "content"=>[
            "application/json"=>[
                "schema"=>[
                    "type"=>"object",
                    "properties"=>[
                        "data"=>[
                            "oneOf"=>[
                                [
                                    "type"=>"array",
                                    "items"=>[
                                        "\$ref"=>"#/components/schemas/{$resourceName}_ResourceObjectCreate"
                                    ]
                                ],
                                [
                                    "\$ref"=>"#/components/schemas/{$resourceName}_ResourceObjectCreate"
                                ]
                            ]
                        ],
                        "jsonapi"=>['$ref'=>"#/components/schemas/jsonapi"]
                    ]
                ]
            ],
            "text/csv"=>[
                "schema"=>[
                    "type"=>"string",
                    "format"=>"binary",
                    "description"=>"CSV with header row of insertable attribute names. Same bulk insert limits and onduplicate behaviour as JSON create.",
                ],
                "example"=>"sku,name,price,is_active\nSKU-CSV-1,Widget,9.99,1\n",
            ],
            "multipart/form-data"=>[
                "schema"=>[
                    "type"=>"object",
                    "properties"=>[
                        "file"=>[
                            "type"=>"string",
                            "format"=>"binary",
                            "description"=>"CSV file (field name may also be csv)",
                        ],
                    ],
                    "required"=>["file"],
                ],
            ],
        ]
    ];
}
function create_request_body_update($resourceName){
    return [
        "required"=>true,
        "content"=>[
            "application/json"=>[
                "schema"=>[
                    "type"=>"object",
                    "properties"=>[
                        "data"=>[
                            '$ref'=>"#/components/schemas/{$resourceName}_ResourceObject"
                        ],
                        "jsonapi"=>['$ref'=>"#/components/schemas/jsonapi"]
                    ]
                ]
            ]
        ]
    ];
}

function create_response_ok_for_create($resourceName) {
    return [
        "description"=>"Record of type $resourceName",
        "content"=>[
            "application/json"=>[
                "schema"=>[
                    "type"=>"object",
                    "properties"=>[
                        "data"=>[
                            "oneOf"=>[
                                [
                                    '$ref'=>"#/components/schemas/{$resourceName}_ResourceObject"
                                ],
                                [
                                    "type"=>"array",
                                    "items"=>['$ref'=>"#/components/schemas/{$resourceName}_ResourceObject"]
                                ]
                            ]
                        ],
                        "jsonapi"=>['$ref'=>"#/components/schemas/jsonapi"]
                    ]
                ]
            ]
        ]
    ];
}

function create_reponse_ok_for_single_update($resourceName) {
    return [
        "application/json"=>[
            "description"=>"Record or array of records of type $resourceName",
            "content"=>[
                "application/json"=>[
                    "schema"=>[
                        "type"=>"object",
                        "properties"=>[
                            "data"=>[
                                "\$ref"=>"#/components/schemas/{$resourceName}_ResourceObjectCreate"
                            ],
                            "jsonapi"=>['$ref'=>"#/components/schemas/jsonapi"]
                        ]
                    ]
                ]
            ]
        ]
    ];
}

function create_response_ok_for_get_records($resourceName){
    return [
        "description"=>"Array of records of type $resourceName",
        "content"=>[
            "application/json"=>[
                "schema"=>[
                    "type"=>"object",
                    "properties"=>[
                        "data"=>[
                            "type"=>"array",
                            "items"=>["\$ref"=>"#/components/schemas/{$resourceName}_ResourceObject"]
                        ],
                        "jsonapi"=>['$ref'=>"#/components/schemas/jsonapi"]
                    ]
                ]
            ]
        ]
    ];
}
function create_response_ok_for_get_record_by_id($resourceName){
    return [
        "description"=>"Array of records of type $resourceName",
        "content"=>[
            "application/json"=>[
                "schema"=>[
                    "type"=>"object",
                    "properties"=>[
                        "data"=>["\$ref"=>"#/components/schemas/{$resourceName}_ResourceObject"],
                        "jsonapi"=>['$ref'=>"#/components/schemas/jsonapi"]
                    ]
                ]
            ]
        ]
    ];
}

/**
 * @param $resourceName
 * @param $resourceSpecifications
 * @return array
 */
function create_param_include($resourceName,$resourceSpecifications) {
    $param = [
        "name"=>"include[{$resourceName}]",
        "in"=>"query",
        "description"=>"Comma separated list of relation names to include in the result",
        "schema"=>["type"=>"string"],
    ];
    $relations = resource_relations($resourceSpecifications);
    if (!empty($relations)) {
        $relNamesList = implode("|", array_keys($relations));
        $param["schema"]["pattern"] = sprintf("^(%s)(,(%s))*$", $relNamesList, $relNamesList);
    }
    return $param;
}

/**
 * @param $resourceName
 * @param $resourceSpecifications
 * @return array
 */
function create_param_filter($resourceName,$resourceSpecifications) {
    return  [
        "name"=>"filter[{$resourceName}]",
        "in"=>"query",
        "description" => "Filter expression with comparisons combined using comma (AND), || (OR), and parentheses.\n".
            "Comparison syntax: {fieldName}{operator}{value}. Operators: =, !=, =~, ~=, ~=~, >, >=, <, <=, >< (IN, semicolon-separated values), ! prefix negates.\n".
            "Examples: bdate>2000-01-01,fname=~John | (city=Washington||city=London)",
        "example" => "bdate>2000-01-01,(fname=~John||fname=~Jane),city><Paris;London",
        "schema"=>[
            "type"=>"string"
        ]
    ];
}

/**
 * @param $resourceName
 * @param $resourceSpecifications
 * @return array
 */
function create_param_fields($resourceName,$resourceSpecifications) {
    $fieldNames = array_keys(swagger_typed_fields($resourceSpecifications));
    $fieldNamesList = implode("|",$fieldNames);
    return  [
        "name"=>"fields[{$resourceName}]",
        "in"=>"query",
        "description"=>"Comma separated list of field names to include in the response. If not specified it will return all fields",
        "example"=>implode(",",$fieldNames),
        "schema"=>[
            "type"=>"string",
            "pattern"=>sprintf("^(%s)(,(%s))*$",$fieldNamesList,$fieldNamesList)
        ]
    ];
}

/**
 * @param $resourceName
 * @param $resourceSpecifications
 * @return array
 */
function create_param_sort($resourceName,$resourceSpecifications) {
    //print_r($resourceSpecifications["fields"]);

    $fieldNames = array_keys(swagger_typed_fields($resourceSpecifications));
    $fieldNamesList = implode("|",$fieldNames);
    return  [
        "name"=>"sort[{$resourceName}]",
        "in"=>"query",
        "description"=>"Comma separated list of field names order the record set.\n"
            ."Default order direction is ascending. Placing a - (minus) in front of the field name will change the sort direction to descending for the column",
        "example"=>$fieldNames[0].(isset($fieldNames[1]) ? ",-{$fieldNames[1]}" : ""),
//        "example"=>$fieldNamesList,
        "schema"=>[
            "type"=>"string",
            "pattern"=>sprintf("^-*(%s)(,-*(%s))*$",$fieldNamesList,$fieldNamesList)
        ]
    ];
}

/**
 * @param $resourceName
 * @param $resourceSpecifications
 * @return array
 */
function create_param_pagination_offset($resourceName,$resourceSpecifications) {
    return  [
        "name"=>"page[{$resourceName}][offset]",
        "in"=>"query",
        "default"=>0,
        "description"=>"The number of records to skip before starting to return results. Used for pagination in combination with the **limit** parameter.",
        "example"=>"50",
        "schema"=>[
            "type"=>"integer"
        ]
    ];
}
function create_param_pagination_limit($resourceName,$resourceSpecifications) {
    return  [
        "name"=>"page[{$resourceName}][limit]",
        "in"=>"query",
        "default"=>100,
        "description"=>"The maximum number of records to return in the response. Used for pagination in combination with the **offset** parameter.",
        "example"=>"10",
        "schema"=>[
            "type"=>"integer"
        ]
    ];
}

function create_param_onduplicate_update($resourceName,$resourceSpecifications) {
    $fieldsList = implode("|", array_keys(swagger_typed_fields($resourceSpecifications)));
    return [
        "name"=>"update",
        "in"=>"query",
        "description"=>"Comma separated list of fields to update when **onduplicate=update**. A field replaces the stored value. `field:add` and `field:sub` add or subtract the inserted value on numeric columns. `field:set` is the same as a bare field.",
        "required"=>false,
        "explode"=>false,
        "schema"=>[
            "type"=>"string",
            "pattern"=>sprintf("^(%s)(:(set|add|sub))?(,(%s)(:(set|add|sub))?)*$",$fieldsList,$fieldsList)
        ]
    ];

}

function create_components(&$openApiSpec,$resourceName,$resourceSpecifications,array $dataModel=[]) {
    //print_r(add_components($resourceName,$resourceSpecifications));
    $openApiSpec["components"]["schemas"] = array_merge($openApiSpec["components"]["schemas"],add_components($resourceName,$resourceSpecifications,$dataModel));


    // request body for create
    $openApiSpec["components"]["requestBodies"]["{$resourceName}_Create"] = create_request_body_create($resourceName);
    // request body for update
    $openApiSpec["components"]["requestBodies"]["{$resourceName}_Update"] = create_request_body_update($resourceName);

    // response for create
    $openApiSpec["components"]["responses"]["{$resourceName}_CreateOK"] = create_response_ok_for_create($resourceName);

    // response for get records
    $openApiSpec["components"]["responses"]["{$resourceName}_GetRecords"] = create_response_ok_for_get_records($resourceName);
    // response for get record by id
    $openApiSpec["components"]["responses"]["{$resourceName}_GetRecordById"] = create_response_ok_for_get_record_by_id($resourceName);

    $openApiSpec["components"]["parameters"]["{$resourceName}_include"] = create_param_include($resourceName,$resourceSpecifications);
    $openApiSpec["components"]["parameters"]["{$resourceName}_filter"] = create_param_filter($resourceName,$resourceSpecifications);
    $openApiSpec["components"]["parameters"]["{$resourceName}_fields"] = create_param_fields($resourceName,$resourceSpecifications);
    $openApiSpec["components"]["parameters"]["{$resourceName}_sort"] = create_param_sort($resourceName,$resourceSpecifications);
    $openApiSpec["components"]["parameters"]["{$resourceName}_limit"] = create_param_pagination_limit($resourceName,$resourceSpecifications);
    $openApiSpec["components"]["parameters"]["{$resourceName}_offset"] = create_param_pagination_offset($resourceName,$resourceSpecifications);
    $openApiSpec["components"]["parameters"]["{$resourceName}_update"] = create_param_onduplicate_update($resourceName,$resourceSpecifications);


}
