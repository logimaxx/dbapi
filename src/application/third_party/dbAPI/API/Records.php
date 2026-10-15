<?php
namespace dbAPI\API;

// @todo: https://jcs.org/2023/07/12/api
/**
 * @todo Log a unique id with every request
 * @todo Be descriptive in your error responses
 */


//require_once(__DIR__."/../../../libraries/Response.php");


require_once(__DIR__."/../../../libraries/Errors.php");
require_once(__DIR__.'/../../../libraries/RecordSet.php');

require_once (APPPATH."/third_party/JSONApi/Autoloader.php");

use http\Exception;
use JSONApi\Autoloader;
Autoloader::register();

use JSONApi\Document;
use dbAPI\API\DBAPIRequest;

//require_once(__DIR__."/../../../libraries/HttpResp.php");

/**
 * Class Records
 * core class for manipulating the records in the Database
 */
class Records {

    private $debug = true;
    /**
     * @var Datamodel $dm
     */
    private $dm;

    /**
     * @var \CI_DB_query_builder $dbdrv
     */
    private $dbdrv;
    private $maxNoRels=10;
    private $configDir;

    /** @var array<string, mixed> */
    private $authConfig = [];

    /** @var \stdClass */
    private $jwtPayload;

    /**
     * Records constructor.
     * @param \CI_DB_query_builder $dbDriver
     * @param Datamodel $dataModel
     */
    function __construct($dbDriver,$dataModel,$apiConfigDir) {
        $this->dm = $dataModel;
        $this->dbdrv = $dbDriver;
        $instance = get_instance();
        $this->maxNoRels = $instance->config->item("inbound_relationships_page_size");
        $this->configDir = $apiConfigDir;
        $this->jwtPayload = new \stdClass();
    }

    /**
     * @param array<string, mixed> $auth
     */
    function setAccessContext(array $auth, \stdClass $payload): void
    {
        $this->authConfig = $auth;
        $this->jwtPayload = $payload;
    }

    private function fieldRestrictionsBypassed(): bool
    {
        return AccessControl::shouldBypassMandatoryFilter($this->authConfig, $this->jwtPayload);
    }

    static function init($dbDriver,$dataModel,$apiConfigDir) {
        return new Records($dbDriver,$dataModel,$apiConfigDir);
    }




    /**
     * @param array<string, array{alias: string, field: string}> $fieldAliases
     * @return int|string
     * @throws \Exception
     */
    private function generate_where_sql($filters, $alias, array $fieldAliases = [])
    {
        return FilterParser::compile($filters, $alias, $fieldAliases);
    }


    /**
     * @param \DBAPIRequest $request
     * @return array
     */
    function generate_select_sql_parts(&$request)
    {
        /**
         * @param \DBAPIRequest $req
         * @param $fields
         * @param $join
         * @param string $tableAlias
         * @param string $parentTableAlias
         * @param int $offset
         */


        $fields = [];
        $join = [];
        recursive_generate_select_and_joins($request, $fields, $join,$request->resourceName);
        return [$fields,$join];
    }

    /**
     * Retrieves records from a database.
     *
     * Inner workings:
     * 1. basic checks (resource exists & is readable)
     * 2. prepare query parameters
     * 3. find out total number of matched records
     * 4. prepare fields selection
     * 5. generate SQL parts
     * 6. create ORDER BY
     * 7. compile SELECT statement
     * 8. run query
     * 9. parse result
     *
     * @param \DBAPIRequest $request
     * @return \RecordSet
     * @throws \Exception
     * @todo: check filtering
     */
    function get_records(DBAPIRequest $request,$dbg=false)
    {
        //print_r($request);
        // check if it is allowed to read the resource data
        $resourceName = $request->resourceName;
        if(!$this->dm->resource_allow_read($resourceName))
            throw new \Exception("Not authorized to read from '$resourceName'",403);

        AccessControl::applyMandatoryFilter($request, $this->dm, $this->authConfig, $this->jwtPayload);

        $filterAst = FilterParser::normalize($request->filter);
        if ($request->filter_advanced) {
            try {
                $adv = is_string($request->filter_advanced)
                    ? FilterParser::parse($request->filter_advanced)
                    : FilterParser::normalize($request->filter_advanced);
                $filterAst = FilterParser::andNodes($filterAst, $adv);
            } catch (Exception $e) {
                throw new Exception('Invalid filter_advanced: '.$e->getMessage(), 400);
            }
        }

        try {
            $relational = FilterParser::buildRelationalJoins($this->dm, $request->resourceName, $filterAst);
        } catch (\dbAPI\API\Exception $e) {
            throw $e;
        } catch (Exception $e) {
            throw new Exception($e->getMessage(), $e->getCode() ?: 400);
        }

        $whereStr = $this->generate_where_sql(
            $filterAst,
            $request->resourceName,
            $relational['fieldAliases']
        );
        if(empty($whereStr)) {
            $whereStr = 1;
        }

        list($select,$join) = $this->generate_select_sql_parts($request);
        $join = FilterParser::dedupeJoins(array_merge($relational['joins'], $join));

        $priKey = $request->primaryKey;
        $joinFanOut = count($join) > 0 && $priKey;
        $countExpr = $joinFanOut
            ? "count(DISTINCT `$request->resourceName`.`$priKey`)"
            : "count(*)";

        // extract total number of records matched by the query
        $countSql = "SELECT $countExpr cnt FROM `$request->resourceName` "
            .implode(" ",$join)
            ." WHERE $whereStr";

        if($dbg) {
            echo $countSql."\n";
        }   
        // echo $countSql."\n";

        $res = $this->dbdrv->query($countSql);
        $err=$this->dbdrv->error();
        if($err["code"]){
            throw new \Exception($err["message"]." on query: $countSql",$err["code"]);
        }
        $row = $res->row();
        $totalRecs = $row->cnt*1;


        $recordSet = new \RecordSet($resourceName,[],$request->offset,$totalRecs);
        // no records found => return empty recordSet
        if($totalRecs==0)
            return $recordSet;

        $orderBy = count($request->sort) ? implode(",",$request->sort) : 1;
        $paginationJoin = "";
        $sqlLimit = " LIMIT {$request->offset},{$request->limit}";
        if ($joinFanOut) {
            $pagedIdsSql = "SELECT DISTINCT `$request->resourceName`.`$priKey` AS `_dbapi_page_id`"
                ." FROM `$request->resourceName` "
                .implode(" ",$join)
                ." WHERE $whereStr"
                ." ORDER BY $orderBy"
                ." LIMIT {$request->offset},{$request->limit}";
            $paginationJoin = " INNER JOIN ($pagedIdsSql) AS `_dbapi_page`"
                ." ON `_dbapi_page`.`_dbapi_page_id`=`$request->resourceName`.`$priKey`";
            $sqlLimit = "";
        }

        // compile SELECT
        $mainSql = "SELECT ".join(",",$select)." FROM `$request->resourceName` "
            .implode(" ",$join)
            .$paginationJoin
            ." WHERE $whereStr"
            ." ORDER BY $orderBy"
            .$sqlLimit;
//        echo $mainSql."\n";

        // run query
        /** @var \CI_DB_result $res */
        $res = $this->dbdrv->query($mainSql);
        $err=$this->dbdrv->error();

        if($err["code"]){
            throw new \Exception($err["message"],$err["code"]);
        }
        //get_instance()->debug_log($mainSql);

        $rows = $res->result_array_num();

        // parse result
        $allRecs = [];
        $seenRecIds = [];
//        print_r($request);
        foreach ($rows as $row) {
            $newRec = $this->parse_result_row($request,$row,$allRecs);
            if ($joinFanOut) {
                $seenKey = $request->resourceName . '_' . $newRec->id;
                if (isset($seenRecIds[$seenKey])) {
                    continue;
                }
                $seenRecIds[$seenKey] = true;
            }
            $recordSet->add_record($newRec);
        }


        return $recordSet;
    }

    /**
     * @param \DBAPIRequest $request
     * @param array $row
     * @param array $allRecs
     * @return mixed|\stdClass
     * @throws \Exception
     */
    function parse_result_row($request,$row,&$allRecs){
        $recId = null;
        if($request->primaryKey) {
            $priKeyPos = $request->fieldsIndexes[$request->primaryKey];
            $fldRecPos = $request->selectFieldsOffset + $priKeyPos;
            $recId = $row[$fldRecPos];

            $objIdx = $request->resourceName."_".$recId;
            if(isset($allRecs[$objIdx])) {
                $rec = &$allRecs[$objIdx];
            }
        }

        // record not yet saved in allRecs -> need to extract it
        if(!isset($rec)){
            $rec = new \Record($request->resourceName,$recId ,[]);
            $relations = $this->dm->get_all_relations($request->resourceName);

            // process outbound (1:1) relations
            foreach ($request->fields as $idx=>$fieldName) {
                // set attribute when fld is not FK
                if(!isset($relations[$fieldName])) {
                    $rec->attributes->$fieldName = $row[$request->fieldsIndexes[$fieldName]+$request->selectFieldsOffset];
                    continue;
                }

                // fld is not requested to be included => create a simple Rec (no attr and rels)
                if(!isset($request->include[$fieldName])) {
                    $relRecId = $row[$request->fieldsIndexes[$fieldName]+$request->selectFieldsOffset];
                    $relationRecord = null;
                    if(!is_null($relRecId)) {
                        $relationRecord = new \Record($relations[$fieldName]["table"],$relRecId);
                    }

                    $rec->add_one2one_relation($fieldName,$relationRecord);
                    continue;
                }

                // fld is requested in the includes => parse result row to generate Recods and add is a relation
                $relationRecord = $this->parse_result_row($request->include[$fieldName],$row,$allRecs);
                if(is_null($relationRecord->id)) {
                    $emptyRelation = null;
                    $rec->add_one2one_relation($fieldName, $emptyRelation);
                    continue;
                }
                $rec->add_one2one_relation($fieldName,$relationRecord);
            }

            // process inbound (1:n) relations
            foreach ($relations as $relName=>$relSpec) {
                // skip 1:1 relations (already processed)
                if($relSpec["type"]=="outbound") {
                    continue;
                }

                // rel not requested to be included => create an empty array relation
                if(!isset($request->include[$relName])) {
                    $rec->add_one2many_relation($relName);
                    continue;
                }

                if (!$request->primaryKey) {
                    $rec->add_one2many_relation($relName);
                    continue;
                }

                $request->include[$relName]->remove_filters_on_field($relSpec["field"]);
                $request->include[$relName]->add_filter_condition($relSpec["field"], "=", $recId);

                $recordSet = $this->get_records($request->include[$relName]);
                $rec->add_one2many_relation($relName,$recordSet);
            }

            if(isset($objIdx) && $recId !== null)
                $allRecs[$objIdx] = $rec;
        }

        return $rec;
    }

    /**
     * @param $resName
     * @param $attributes
     * @return mixed
     * TODO: review this method
     * @throws \Exception
     */
    private function validate_insert_data($resName, $attributes) {
        $attributesNames = array_keys($attributes);

        foreach($this->dm->get_fields($resName) as $fldName=> $fldSpec) {
            if($fldSpec["required"] && is_null($fldSpec["default"]) && !in_array($fldName,$attributesNames))
                throw new \Exception("Required attribute '$fldName' of '$resName' not provided",400);

            // field not allowed to insert
            if(!$this->dm->field_is_insertable($resName,$fldName) && in_array($fldName,$attributesNames))
                throw new \Exception("Attribute '$resName/$fldName' not allowed to be inserted",400);
        }

        foreach($attributes as $attrName=> $attrVal) {
            $attrVal = $this->dm->is_valid_value($resName,$attrName,$attrVal);

            /**
             * TODO: instead of just checking if value is an object as exception when value type validation fails
             *       implement a proper mechanism inside the is_valid_value method
             */
            if(!is_object($attrVal)) {
                $attributes[$attrName] = $attrVal;
            }
        }
        return $attributes;
    }

    /**
     * create new Record
     * TODO: clarify best approach about what to return after inserting OK....
     *
     * @param $table
     * @param object $data data to be inserted
     * @param $watchDog
     * @param string $onDuplicate behaviour flags
     * @param array<string, list<array{name: string, op: string}>> $fieldsToUpdate
     * @param $path
     * @param $includes
     * @return \Response
     * @throws \Exception
     */
    function insert($table, $data, $watchDog, $onDuplicate, $insertIgnore, $fieldsToUpdate, $path, &$includes) {

        if($watchDog==0) {
            throw new \Exception("Maximum recursion level has been reached. Aborting. Please review your nested data.",400);
        }

        //$table = $data->type;
//        if($data->type!=$table) {
//            throw new \Exception("Invalid data type '$data->type' for '$table'",400);
//        }

        // check if resource exists
        if(!$this->dm->resource_exists($table)) {
            throw new \Exception("Resource '$table'' not found",404);
        }

        // check if client is authorized to insert into resource
        if(!$this->dm->resource_allow_insert($table)) {
            throw new \Exception("Not authorized to insert into resource '$table'",401);
        }

        // validate attributes
        $attributes = $data->attributes;
        $relations = isset($data->relationships)?$data->relationships:[];
        $idFld = $this->dm->get_primary_key($table);

        $insertData = [];
        if ($idFld && isset($data->id)) {
            $insertData[$idFld] = $data->id;
        }

        // collect attributes
        foreach($attributes as $fldName=>$value) {
            if(!$this->dm->field_is_insertable($table,$fldName)) {
                throw new \Exception("Not allowed to insert data in field '$fldName' of table '$table'",400);
            }
            $insertData[$fldName] = $value;
        }

        AccessControl::applyMandatoryAssign($insertData, $table, $this->dm, $this->authConfig, $this->jwtPayload);

        $one2nRelations = [];

        // iterate relations and insert recursive if it is the case
        foreach ($relations as $relName=>$relData) {
            // gets relationship config. Throws an error when relation is not valid
            $relSpec = $this->dm->get_relationship($table, $relName);

            if(empty($relData)) {
                continue;
            }


            // todo: implement full validation in input_validator and remove the code bellow
            if(!is_object($relData)) {
                throw new \Exception("Invalid relationship '$relName' data: invalid format ",400);
            }

            if(!isset($relData->data)) {
                throw new \Exception("Invalid relationship '$relName' data: 'data' property not set",400);
            }

            if(is_null($relData->data)) {
                continue;
            }

            // relation type vs data type: object for outbound relations; array for inbound relations
            if ($relSpec["type"]=="inbound" && !is_array($relData->data)) {
                throw new \Exception("Invalid 1:n relation '$relName' for '$table'",400);
            }

            if ($relSpec["type"]=="outbound" && !is_object($relData->data) ) {
                throw new \Exception("Invalid 1:1 relation '$relName' for '$table'",400);
            }


            // inbound relation (1:n) add to stack for later insert
            if(is_array($relData->data) && $relSpec["type"]=="inbound") {
                $one2nRelations[$relName] = [
                    "data"=>$relData->data,
                    "spec"=>$relSpec
                ];
                continue;
            }

            //////////////////////////////////////////////////
            // continue with outbound relation (1:1) processing
            // validate object structure
            ///////////////////////////////////////////////////

            if(!$this->dm->is_valid_field($table,$relName))
                throw new \Exception("Invalid 1:1 relation '$relName' for '$table'",400);

//            if(!isset($relData->data->type))
//                throw new \Exception("Invalid relationship data: missing '$relName' type",400);

            $fk = (object)$this->dm->get_outbound_relation($table,$relName);

            // todo: data obfuscation ...........
//            if($fk->table!==$relData->data->type)
//                throw new \Exception("Invalid relationship data: invalid type for relationship '$relName'",400);

            $newPath = $path==null?$relName:$path.".$relName";
            if(isset($relData->data->id)) {
                // related record exists already; just set the id and continue
                // still... it does not check if it actually exists... but on insert it will throw an error if ID is fake
                if(!in_array($newPath,$includes))
                    $includes[] = $newPath;
                $insertData[$relName] = $relData->data->id;
                continue;
            }

            // create 1:1 related record
            if(isset($relData->data->attributes)) {

                $insertData[$relName] = $this->insert($fk->table,$relData->data,$watchDog-1,$onDuplicate,$insertIgnore,$fieldsToUpdate,$newPath,$includes);
                if(!in_array($newPath,$includes)) {
                    $includes[] = $newPath;
                }
            }
        }

        $insertData = $this->validate_insert_data($table,$insertData);

        // check insert data for non-scalar values and throw error in case found
        foreach ($insertData as $key=>$value) {
            if($value!==null && !is_scalar($value)) {
                throw new \Exception("Invalid value for $key: ".json_encode($value));
            }
            $this->dbdrv->set($key,$value);
        }

        $insSql = $this->dbdrv->get_compiled_insert($table);



        if($insertIgnore) {
            $insSql = str_replace("INSERT INTO","INSERT IGNORE INTO",$insSql);
        }
//        echo $insSql;
        // todo: should put this in an external file: configure behaviour to update fields (database specific)
        switch ($onDuplicate) {
            case "update":
                if (empty($fieldsToUpdate[$table]))
                    break;

                $updStr = $this->duplicateKeyUpdateAssignments($table, $fieldsToUpdate[$table], $insertData, $idFld);

                if(count($updStr))
                    $insSql .= " ON DUPLICATE KEY UPDATE " . implode(",", $updStr);
                break;
            case "ignore":
                if($idFld)
                    $insSql .= " ON DUPLICATE KEY UPDATE `$idFld`=`$idFld`";
                break;
            case "error":
                break;
            default:
                throw new \Exception("Invalid 'onduplicate' parameter value.");
        }

        // insert data in DB

        $this->dbdrv->db_debug = false;

        //echo $insSql;
        $res = $this->dbdrv->query($insSql);
        $err=$this->dbdrv->error();
        if($err["code"]){
            throw new \Exception($err["message"]." on query: $insSql",$err["code"]);
        }

        //$this->dbdrv->db_debug = true;



        // retrieve resource ID (mysql specific)
        // todo: evaluate impact for other DB engines and implement
        $newRecId = $this->dbdrv->insert_id();


        if($this->dbdrv->affected_rows()!==1) {

        }

        if (!$newRecId && $this->dbdrv->affected_rows() === 1 && $idFld && isset($insertData[$idFld]) && is_scalar($insertData[$idFld])) {
            $newRecId = $insertData[$idFld];
        }

        if (!$newRecId && $idFld) {
            $selSql = $this->dbdrv
                ->where($insertData)
                ->get_compiled_select($table);

            $q = $this->dbdrv->query($selSql);
            $err=$this->dbdrv->error();
            if($err["code"]){
                throw new \Exception($err["message"]." on query: $selSql",$err["code"]);
            }

            $cnt = $q->num_rows();
            if($cnt > 1) {
                log_message("error", "More then one records returned on Insert new record: $insSql / $selSql");
                return null;
            }

            $newRecId = $q->row()->$idFld;
        }

        // create outbound relations
        if($newRecId && $one2nRelations) {
            foreach ($one2nRelations as $relName=>$relData) {
                $relationPeerTable = $relData["spec"]["table"];
                $relationPeerField = $relData["spec"]["field"];
                $rels = $relData["data"];

                $newPath = $path==null ? $relName : "$path.$relName";

                // iterate through data
                foreach ($rels as $relItem){
                    // check relation data type
                    $objType = $this->get_object_type($relItem);
                    $fkFld = $relationPeerField;

                    if(!$this->dm->resource_allow_update($relationPeerTable)) {
                        throw new \Exception("Not allowed to update relationship $relName on $table",403);
                    }

                    if(!in_array($newPath,$includes)) {
                        $includes[] = $newPath;
                    }

                    switch ($objType) {
                        // data is a resource indicator object = related record exist already
                        // => perform an update with the id of the newly created object for the FK field
                        case "ResourceIndicatorObject":
                            // update related record

                            $relItem->attributes = (object) [
                                $relationPeerField=>$newRecId
                            ];

                            $this->update_by_id($relationPeerTable,$relItem->id,$relItem);
                            break;
                            // data is of newResourceObject type => new related record must be created
                        case "newResourceObject":
                            // insert new related record

                            $relItem->attributes->$fkFld = $newRecId;

                            $this->insert($relationPeerTable,$relItem,$watchDog-1,$onDuplicate,$insertIgnore,$fieldsToUpdate,$newPath,$includes);
                            break;
                        case "DocumentObject":
                            break;
                        default:
                            throw new \Exception("Invalid '$relName' relationship data type ($objType) : ".json_encode($relItem),403);
                    }
                }
            }
        }

        return $newRecId;
    }


    /**
     * @param $table
     * @param $attributes
     * @param $paras
     * @return mixed
     * @throws \Exception
     */
    function update_attributes_by_filter($table, $attributes, $paras)
    {
        if (array_key_exists("custom_where", $paras)) {
            $where = $paras["custom_where"];
        } else {
            $filter = $paras["filter"][$table] ?? $paras["filter"];
            $filterAst = is_array($filter) ? $filter : FilterParser::normalize($filter);
            $where = AccessControl::compileMandatoryWhere(
                $table,
                $this->dm,
                $this->authConfig,
                $this->jwtPayload,
                $filterAst
            );
        }

        return $this->update_attributes($table,$attributes,$where);
    }

    /**
     * @param $table
     * @param $attributes
     * @param $where
     * @return mixed
     * @throws \Exception
     */
    function update_attributes($table, $attributes, $where)
    {

//        print_r([$table,$attributes,$where]);
//        $config = $this->dm->get_config($table);
        $attributes = $this->dm->validate_object_attributes(
            $table,
            $attributes,
            "upd",
            $this->fieldRestrictionsBypassed()
        );
        foreach ($attributes as $key => $val) {
            if (!$this->dm->is_required($table, $key) && $val === '') {
                $attributes[$key] = null;
            }
        }

        // configure query
        $updateSql = $this->dbdrv
            ->where($where)
            ->set($attributes)
            ->get_compiled_update($table);


        $this->dbdrv->query($updateSql);
        $err=$this->dbdrv->error();
        if($err["code"]){
            throw new \Exception($err["message"]." on query: $updateSql",$err["code"]);
        }

        return $this->dbdrv->affected_rows();
    }

    /**
     * @param string $table
     * @param string $id
     * @param array $attributes
     * @return string mixed
     * @throws \Exception
     */
    private function update_attributes_by_id($table, $id, $attributes)
    {
        $priKey = $this->dm->get_primary_key($table);
        if (!$priKey) {
            throw new \Exception("Update by ID not allowed: table '$table' has no primary key", 400);
        }

        $keyFields = $this->dm->get_key_flds($table);

        $whereArr = array();

        // check for duplicates on key fields
        // @todo: contemplate if this code is really needed. Maybe a simple capture of the mysql error should do the job
        // build where part with key fields
        foreach($attributes as $name=>$value) {
            if(in_array($name,$keyFields)) {
                $whereArr[] = "$name='$value'";
            }
        }

        // run the query
        if(count($whereArr)) {
            $sql = "SELECT * FROM $table WHERE $priKey!='$id' AND (".implode(" OR ",$whereArr).")";

            $res = $this->dbdrv->query($sql);
            $err=$this->dbdrv->error();
            if($err["code"]){
                throw new \Exception($err["message"]." on query: $sql",$err["code"]);
            }

            if($res->num_rows()) {
                throw new \Exception("Duplicate key fields for $table/$id: ".json_encode($attributes),409);
            }
        }

        $where = AccessControl::compileMandatoryWhere(
            $table,
            $this->dm,
            $this->authConfig,
            $this->jwtPayload,
            null,
            $id
        );
        if (!$this->update_attributes($table, $attributes, $where)) {
            return null;
        }

        if(isset($attributes[$priKey]))
            return $attributes[$priKey];

        return $id;

    }

    /**
     * @param string $table
     * @param string $id
     * @param array $relationships
     */
    function updateRelations($table, $id, $relationships) {

    }

    /**
     * update Record
     * @param $table
     * @param $id
     * @param $resourceData
     * @return string
     * @throws \Exception
     */
    function update_by_id($table, $id, $resourceData) {

        if(!$this->dm->get_primary_key($table))
            throw new \Exception("Update by ID not allowed: table '$table' does not have primary key/unique field.",500);

        // extract 1:1 relation data and insert (or update)
        if(isset($resourceData->relationships)) {
            foreach ($resourceData->relationships as $relName => $relData) {
                $relData = $relData->data;
                $relSpec = $this->dm->get_relationship($table, $relName);

                if ($relSpec["type"] === "outbound") {
//                    log_message("debug",print_r($resourceData,true));
                    if (!isset($resourceData->attributes))
                        $resourceData->attributes = new \stdClass();

                    if($relData === null) {
                        $resourceData->attributes->$relName = null;
                        continue;
                    }
                    if (!isset($relData->type)) {
                        $relData->type = $relSpec["table"];
                    }
//                        throw new \Exception("Invalid empty data type for relation '$relName' of record ID $id of type $table", 400);

                    if (isset($relData->id) && $relData->id !== null) {
                        $this->update_by_id($relData->type, $relData->id, $relData);
                        $resourceData->attributes->$relName = $relData->id;
                        continue;
                    }

                    if ($relData->type !== $relSpec["table"])
                        throw new \Exception("Invalid data type for relation '$relName' of record ID $id of type $table", 400);

                    $includes = [];
                    //                echo "inserting";
                    //                print_r($relData);
                    $flds = $this->dm->get_fields($relData->type);
                    array_splice($flds, array_search( $this->dm->get_primary_key($relData->type), $flds),1);
                    $resourceData->attributes->$relName = $this->insert($relData->type, $relData, get_instance()->get_max_insert_recursions(),
                        "update", false, $flds, null, $includes);
                    continue;
                }

                if ($relSpec["type"] === "inbound") {
                    if(!is_array($relData)) {
                        throw new \Exception("Invalid relation data '$relName' of record ID $id of type $table: not an array",400);
                        
                    }
                    foreach ($relData as $item) {
                        $this->update_by_id($item->type,$item->id,$item);
                    }
                }
            }
        }


        if(isset($resourceData->attributes) && count(get_object_vars($resourceData->attributes))) {
            $resourceData->attributes = $this->dm->validate_object_attributes(
                $table,
                $resourceData->attributes,
                "upd",
                $this->fieldRestrictionsBypassed()
            );
            return $this->update_attributes_by_id($table,$id,$resourceData->attributes);
        }

        // todo: update 1:n relationships
//        if()



        return  $id;
    }


    /**
     * delete Record id $id from $database/$table
     *
     * @param string $tableName
     * @param string $recId
     *
     * @return bool
     * @throws \Exception
     */
    function delete_by_id($tableName, $recId) {
        // check if resource exists
        if(!$this->dm->resource_exists($tableName))
            throw new \Exception("Resource '$tableName' not found",404);

        if(!$this->dm->resource_allow_delete($tableName))
            throw new \Exception("Not authorized to delete from $tableName",401);

        $idFld = $this->dm->get_primary_key($tableName);
        if (!$idFld) {
            throw new \Exception("Delete by ID not supported: resource '$tableName' has no primary key", 400);
        }

        $where = AccessControl::compileMandatoryWhere(
            $tableName,
            $this->dm,
            $this->authConfig,
            $this->jwtPayload,
            null,
            $recId
        );
        $this->dbdrv->where($where);
        $this->dbdrv->delete($tableName);

        $err=$this->dbdrv->error();
        if($err["code"]){
            throw new \Exception($err["message"]." on delete record $tableName:$recId",$err["code"]);
        }
        if($this->dbdrv->affected_rows()) {
            return true;
        }
        throw new \Exception("Record not found",404);
    }

    /**
     * @param $tableName
     * @param $recId
     * @return int
     */
    function delete_record_by_id($tableName,$recId) {
        $idFld = $this->dm->get_primary_key($tableName);
        if (!$idFld) {
            throw new \Exception("Delete by ID not supported: resource '$tableName' has no primary key", 400);
        }
        $this->dbdrv->where($idFld, $recId)->delete($tableName);
        $err = $this->dbdrv->error();
        if ($err["code"]) {
            $errno = (int) $err["code"];
            // 1451: parent row still referenced. Surface as 409 so clients do not treat it as success.
            $status = ($errno === 1451 || $errno === 1217) ? 409 : $errno;
            throw new \Exception($err["message"]." on delete record $tableName:$recId", $status);
        }
        $affected = $this->dbdrv->affected_rows();
        return $affected > 0 ? $affected : 0;
    }
    /**
     * @param string $tableName
     * @param array $where
     * @return bool
     * @throws \Exception
     */
    function delete_by_where($tableName, $filter)
    {
        // check if resource exists
        if(!$this->dm->resource_exists($tableName))
            throw new \Exception("Resource '$tableName' not found",404);

        if(!$this->dm->resource_allow_delete($tableName))
            throw new \Exception("Not authorized to delete from $tableName",401);

        $filterAst = $filter[$tableName] ?? $filter;
        $where = AccessControl::compileMandatoryWhere(
            $tableName,
            $this->dm,
            $this->authConfig,
            $this->jwtPayload,
            is_array($filterAst) ? $filterAst : FilterParser::normalize($filterAst)
        );

        $this->dbdrv->where($where);
        $this->dbdrv->delete($tableName);
        $err=$this->dbdrv->error();
        if($err["code"]){
            throw new \Exception($err["message"]." on delete by where from $tableName",$err["code"]);
        }
        if($this->dbdrv->affected_rows())
            return true;

        throw new \Exception("Records not found",404);
    }

    /**
     * @param $obj
     * @return string
     * @throws \Exception
     */
    private function get_object_type($obj)
    {
        if(!is_object($obj))
            return "NoObject";

        if(property_exists($obj,"data"))
            return "DocumentObject";

//        if(property_exists($obj,"type"))
//            return "UnknownObject";

        if(property_exists($obj,"id"))
            return property_exists($obj,"attributes")?"ResourceObject":"ResourceIndicatorObject";
        else
            return property_exists($obj,"attributes")?"newResourceObject":"InvalidObject";
    }

    /**
     * ON DUPLICATE KEY UPDATE assignments. Operators are a fixed set; values stay in the INSERT.
     * The primary key is assigned to itself through LAST_INSERT_ID so the readback returns the conflicting row.
     *
     * @param list<array{name?: string, op?: string}> $specs
     * @return list<string>
     */
    private function duplicateKeyUpdateAssignments($table, array $specs, array $insertData, $idFld)
    {
        $assignments = [];
        $seen = [];
        $pkAssigned = false;
        foreach ($specs as $spec) {
            $name = isset($spec["name"]) ? (string) $spec["name"] : "";
            $op = isset($spec["op"]) && $spec["op"] !== "" ? (string) $spec["op"] : "set";
            if (!in_array($op, ["set", "add", "sub"], true)) {
                throw new \Exception("Invalid ON DUPLICATE update operator '$op'", 400);
            }
            $quoted = $this->quoteUpdateIdent($name);
            if (isset($seen[$name])) {
                throw new \Exception("ON DUPLICATE UPDATE failure: field '$name' is listed more than once", 400);
            }
            $seen[$name] = true;
            if ($idFld && $name === $idFld && $op !== "set") {
                throw new \Exception("ON DUPLICATE UPDATE failure: operator '$op' is not allowed on primary key '$name'", 400);
            }
            if (!$this->dm->field_is_updateable($table, $name)) {
                throw new \Exception("ON DUPLICATE UPDATE failure: not allowed to update field '$name'", 400);
            }
            if (!array_key_exists($name, $insertData)) {
                throw new \Exception("ON DUPLICATE UPDATE failure: field '$name' is not in the insert", 400);
            }
            if ($idFld && $name === $idFld) {
                $assignments[] = "$quoted=LAST_INSERT_ID($quoted)";
                $pkAssigned = true;
                continue;
            }
            if ($op !== "set" && !$this->fieldSupportsArithmetic($table, $name)) {
                throw new \Exception("ON DUPLICATE UPDATE failure: operator '$op' requires a numeric field '$name'", 400);
            }
            if ($op === "add") {
                $assignments[] = "$quoted=$quoted+COALESCE(VALUES($quoted),0)";
            } elseif ($op === "sub") {
                $assignments[] = "$quoted=$quoted-COALESCE(VALUES($quoted),0)";
            } else {
                $assignments[] = "$quoted=VALUES($quoted)";
            }
        }
        if ($idFld && !$pkAssigned && $this->isUpdateIdent($idFld)) {
            array_unshift($assignments, $this->quoteUpdateIdent($idFld) . "=LAST_INSERT_ID(" . $this->quoteUpdateIdent($idFld) . ")");
        }
        return $assignments;
    }

    private function fieldSupportsArithmetic($table, $name)
    {
        $fields = $this->dm->get_fields($table);
        $proto = $fields[$name]["type"]["proto"] ?? "";
        return in_array($proto, ["int", "tinyint", "smallint", "mediumint", "bigint", "decimal", "float", "double", "real", "serial"], true);
    }

    private function isUpdateIdent($name)
    {
        return is_string($name) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1;
    }

    private function quoteUpdateIdent($name)
    {
        if (!$this->isUpdateIdent($name)) {
            throw new \Exception("Invalid field name '$name'", 400);
        }
        return "`$name`";
    }

}

function generate_where_condition($where, $alias) {
    if (!is_object($where) || !property_exists($where, 'left')) {
        if (function_exists('log_message')) {
            log_message('debug', 'invalid filter entry');
        }
        return 'FALSE';
    }
    return FilterParser::compileCompareObject($where, $alias);
}

function recursive_generate_select_and_joins(&$req, &$fields, &$join, $tableAlias, $parentTableAlias="",$offset=0)
{
    $req->selectFieldsOffset = $offset;
    foreach ($req->fields as $fld) {
        if ($fld === null || $fld === '') {
            continue;
        }
        $fields[] = "`$tableAlias`.`$fld`";
    }

    // n:1 relationship -> skip
//    echo "reqqqq....\n";
//    print_r($req);
    if($req->relSpec && $req->relSpec["type"]=="outbound") {
        $localColumn = dbapi_outbound_local_column($req->relName ?? '', $req->relSpec);
        $join[] = "LEFT JOIN `$req->resourceName` AS `$tableAlias` ".
            "ON `$tableAlias`.`{$req->relSpec['field']}`=`$parentTableAlias`.`{$localColumn}`";
    }

    foreach ($req->sort as $idx=>$sort) {
        $req->sort[$idx] = "`$tableAlias`.$sort";
    }


    $width = count($req->fields);
    $offset += $width;
    foreach ($req->include as $relName=>$relReq) {
        if($relReq->relSpec["type"]=="outbound") {
            $childWidth = recursive_generate_select_and_joins($relReq, $fields, $join,
                $tableAlias . "_" . $relName, $tableAlias, $offset);
            $width += $childWidth;
            $offset += $childWidth;
        }
    }
    return $width;
}