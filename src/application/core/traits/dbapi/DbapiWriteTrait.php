<?php
defined('BASEPATH') OR exit('No direct script access allowed');

use JSONApi\Document;

/**
 * @property CI_Config $config
 * @property CI_Loader $load
 * @property CI_Input $input
 * @property Utilities $utilities
 */
trait DbapiWriteTrait
{
    function update_where(string $configName, string $resourceName, array $paras=null)
    {
        $this->_init($configName);

        try {
            if(!$this->apiDm->resource_exists($resourceName))
                throw new Exception("Resource $resourceName not found",404);

            $inputData = $this->get_input_data();
        }
        catch (Exception $e) {
            HttpResp::json_out(
                $e->getCode(),
                JSONApi\Document::error_doc($this->JsonApiDocOptions, JSONApi\Error::from_exception($e) )->json_data()
            );
        }

        if(count(get_object_vars($inputData->data->attributes))===0) {
            $doc = JSONApi\Document::create(["baseUrl" => ""])->setData(null)->setMeta(JSONApi\Meta::factory(["total"=>0]));
            HttpResp::json_out(
                200,
                $doc->json_data()
            );
        }


        if(is_null($paras))
            $paras = $this->get_req_parameters($resourceName);

        if(!$paras["filter"] && !$paras["where"]) {
            $e = new Exception("No filtering condition provided",400);
            HttpResp::json_out(
                $e->getCode(),
                JSONApi\Document::error_doc($this->JsonApiDocOptions, JSONApi\Error::from_exception($e) )->json_data()
            );
        }

        //echo json_encode($paras,JSON_PRETTY_PRINT);
        $affectedRows = $this->recs->update_attributes_by_filter($resourceName,$inputData->data->attributes,$paras);
        if(!$affectedRows) {
            // todo: update set
            $doc = JSONApi\Document::create(["baseUrl"=>""])->setData(null)->setMeta(JSONApi\Meta::factory(["total"=>0]));
            HttpResp::json_out(
                200,
                $doc->json_data()
            );
        }
        $this->get_records($configName,$resourceName,null,$paras);
    }

    /**
     * Update multiple records of different types with a single call based on filter
     * @param string $configName
     * @param string $resourceName
     * @param array|null $inputData
     * @throws \dbAPI\API\Exception
     * @todo to be implemented
     */
    function bulk_update(string $configName,string $resourceName,array $inputData=null)
    {
        $this->_init($configName);

        // todo: finish it

        // & validate it
        try {
            if(!$this->apiDm->resource_exists($resourceName))
                throw new Exception("Resource $resourceName not found",404);

            if(!$inputData)
                $inputData = $this->get_input_data();
        }
        catch (Exception $exception) {
            $errors = JSONApi\Error::from_exception($exception);
            HttpResp::json_out(400,
                JSONApi\Document::error_doc($this->JsonApiDocOptions,$errors)->json_data()
            );
        }

        $updateRecords = $inputData->data;
        if (!is_array($updateRecords)) {
            HttpResp::json_out(400, HttpResp::errorPayload('Bulk update requires a data array', null, 400));
            return;
        }
        try {
            ApiSafety::assertBulkUpdateCount(count($updateRecords));
        } catch (Exception $e) {
            HttpResp::json_out(HttpResp::exceptionHttpStatus($e->getCode()), HttpResp::errorPayload($e->getMessage(), $e->getCode(), HttpResp::exceptionHttpStatus($e->getCode())));
            return;
        }

        $ids = [];
        $exceptions = [];
        foreach ($updateRecords as $idx=>$itemData) {
            if(!isset($itemData->id))
                continue;

            try {
                $ids[] = $this->update_single_record($configName,($itemData->type ? $itemData->type  : $resourceName), $itemData->id,(object) ["data"=>$itemData]);
            }
            catch (Exception $e) {
                $exceptions[] = new Exception("Failed to update record ID: $itemData->id: ".$e->getMessage(),$e->getCode());
            }
        }

        $_GET["filter"] = "id><".implode(";",$ids);
        $request = $this->get_dbapi_request($resourceName);
        $this->get_records($configName,$resourceName,null,$request);


        $doc = Document::create($this->JsonApiDocOptions,[]);

        if(count($exceptions)) {
            foreach ($exceptions as $exception) {
                $doc->addError(\JSONApi\Error::from_exception($exception));
            }
        }
        //print_r($doc);
        HttpResp::jsonapi_out(200,$doc);
    }


    /**
     * @param $configName
     * @param $resourceName
     * @throws Exception
     * @todo to be implemented
     */
    /**
     * @param string $configName
     * @param string $resourceName
     * @throws Exception
     */
    function bulk_delete(string $configName, string $resourceName)
    {
        $this->_init($configName);

        // check if table exists
        if (!$this->apiDm->resource_exists($resourceName)) {
            HttpResp::not_found();
        }

        $paras = $this->get_req_parameters($resourceName);
//        print_r($paras);
        if(!$paras["filter"])
            HttpResp::method_not_allowed();
        try {
            $this->recs->delete_by_where($resourceName,$paras["filter"]);
            HttpResp::no_content();
        }
        catch (Exception $exception) {
            $doc = Document::not_found($this->JsonApiDocOptions, "Not found", 404);
            HttpResp::json_out(404, $doc->json_data());
        }

    }


    /**
     * @param string $configName
     * @param string $resourceName
     * @param string $recId
     * @param array|null $updateData
     * @return string
     * @throws Exception
     * @todo validate it
     */
    function update_single_record(string $configName, string $resourceName, string $recId, $updateData=null)
    {
        $this->_init($configName);

        $internal = !is_null($updateData);

        // validation section
        try {
            if(!$internal) {
                $updateData = $this->get_input_data();
            }

            $updateData = $updateData->data;

            if(!$this->apiDm->resource_exists($resourceName))
                throw new Exception("Resource '$resourceName' not found",404);

            if (($updateData->type ?? null) && $resourceName !== $updateData->type) {
                throw new Exception("Object type mismatch; '$updateData->type' instead of '$resourceName' ", 400);
            }

            if ("".$recId !== "" . ($updateData->id ?? ''))
                throw new Exception("Record ID mismatch $recId vs $updateData->id",400);

            $resKeyFld = $this->apiDm->get_primary_key($resourceName);
            if(!$resKeyFld)
                throw new Exception("Cannot update by id: resource $resourceName is not configured with a primary key",400);


        }
        catch (Exception $e) {
            if($internal) throw $e;

            HttpResp::json_out(HttpResp::exceptionHttpStatus($e->getCode()),
                JSONApi\Document::error_doc($this->JsonApiDocOptions, JSONApi\Error::from_exception($e) )->json_data()
            );
        }


        $this->apiDb->trans_begin();

        // perform update
        try {
            $this->recs->update_by_id($resourceName, $recId, $updateData);

            $this->apiDb->trans_commit();

            $def = $this->apiDm->get_config($resourceName);
            if(isset($def["hooks"]["update"])) {
                $this->publishWebhookEvent($configName,$resourceName,$def["hooks"]["update"],[
                    "event"=>"update",
                    "oldRecId" => $recId,
                    "newRecId" => $updateData[$this->apiDm->get_primary_key($resourceName)] ?? $recId
                ]); 
            }

            if($internal)
                return $recId;


            $this->get_records($configName,$resourceName,$recId);

        }
        catch (Exception $exception) {
            $this->apiDb->trans_rollback();
            if($internal) // bubble up error to higher level
                throw $exception;
            HttpResp::jsonapi_out(HttpResp::exceptionHttpStatus($exception->getCode()), Document::from_exception($this->JsonApiDocOptions,$exception));
        }

        return $recId;
    }


    /**
     * extracts query parameters and returns them as an array:
     * - include: comma separated list of related resources to include
     * - fields[resourceName]: comma separated list of fields to include from the specified resourceName
     * - filter: filtering criteria @todo write more details
     * - page[offset]: page offset
     * - page[limit]: page size
     * - sort: comma separated list of sorting conditions
     * - onduplicate: parameter describing the behaviour when a duplicate key occurs when inserting (or updating); possible values: ignore, update, error
     * - update: comma separated list of fields to update when onduplicate=update.
     * - _jwt
     * - api_key
     * @return array
     *
     * @
     */
    public function create_records(string $configName, string $resourceName, $input=null)
    {
        $this->_init($configName);

        // print_r($input);
        // get input data (JSON:API, or CSV / multipart CSV → same shape)
        try {
            // if (is_object($input)) {
            //     $input = json_decode(json_encode($input), true);
            // }
            $this->load->helper('csv_import');
            if (is_null($input) && dbapi_is_csv_content_type($_SERVER['CONTENT_TYPE'] ?? null)) {
                $csvText = dbapi_read_csv_request_body($_SERVER['CONTENT_TYPE'] ?? null);
                $input = dbapi_parse_csv_import($csvText, $resourceName, $this->apiDm);
            } else {
                $input = $this->get_input_data($input);
            }
        }
        catch (Exception $e) {
            $code = (int) $e->getCode();
            if ($code < 400 || $code > 599) {
                $code = 400;
            }
            HttpResp::json_out(
                $code,
                JSONApi\Document::error_doc($this->JsonApiDocOptions, JSONApi\Error::from_exception($e) )->json_data()
            );
        }


        if(is_null($input)) {
            HttpResp::json_out(400,
                Document::error_doc($this->JsonApiDocOptions, [
                    \JSONApi\Error::factory(["message" => "Empty input data not allowed", "code" => 400])
                ])->json_data()
            );
        }

        // configure onDuplicate behaviour
        $onDuplicate = $this->input->get("onduplicate");
        if(!in_array($onDuplicate,["update","ignore","error"])) {
            $onDuplicate = "error";
        }
        $insertIgnore = false;
        if($onDuplicate=="ignore") {
            $insertIgnore  = true;
        }
        // configure fields to be updated when onduplicate is set to "update"
        $updateFields = [];
        if($onDuplicate=="update") {
            $updateFields = getFieldsToUpdate($this->input,$resourceName);
            if(!count($updateFields))
                $onDuplicate = null;
        }

        $singleInsert = !is_array($input->data);
        $entries = $singleInsert ? [$input->data] : $input->data;

        try {
            ApiSafety::assertBulkInsertCount(count($entries));
        } catch (Exception $e) {
            HttpResp::json_out(
                $e->getCode() ?: 400,
                HttpResp::enrichJsonApiErrors(
                    Document::error_doc($this->JsonApiDocOptions, [
                        \JSONApi\Error::factory(['message' => $e->getMessage(), 'code' => $e->getCode() ?: 400]),
                    ])->json_data()
                )
            );
            return;
        }

        // starts transaction
        $this->apiDb->trans_begin();

        $includes = get_include($this->input);
        $newRecIds = [];
        foreach($entries as $entry) {
            try {
                /**
                 * @todo: what happens when the records are not uniquely identifiable? think about adding an extra behavior
                 */
                $recId = $this->recs->insert($resourceName, $entry, $this->insertMaxRecursionLevel,
                    $onDuplicate, $insertIgnore, $updateFields,null,$includes);
                $newRecIds[] = $recId;
            }
            catch (Exception $exception)
            {
                $this->apiDb->trans_rollback();
                $respHttpCode = 500;
                switch ($exception->getCode()) {
                    case 1062:
                        $respHttpCode = 409;
                        break;
                }
                HttpResp::json_out($respHttpCode, Document::from_exception($this->JsonApiDocOptions,$exception)->json_data());
            }
        }



        $this->apiDb->trans_commit();
        //return [$insertedRecords,$totalRecords];
        try {
            $request = $this->get_dbapi_request($resourceName);

        }
        catch (Exception $exception) {
            var_dump($exception->getTraceAsString());
            HttpResp::exception_out($exception);
        }
        // Drop list filters/sort from the POST URL for the post-create readback.
        // Otherwise e.g. POST ?filter=is_customer=1 with is_customer=0 inserts OK
        // then 404s because the new row does not match the list filter.
        $request->filter = [];
        $request->filter_advanced = null;
        $request->sort = [];

        $def = $this->apiDm->get_config($resourceName);

        $pk = $this->apiDm->get_primary_key($resourceName);

        if ($singleInsert) {
            $recId = array_pop($newRecIds);
            if (isset($def['hooks']['create'])) {
                $this->publishWebhookEvent($configName, $resourceName, $def['hooks']['create'], [
                    'event' => 'create',
                    'recId' => $recId,
                ]);
            }
            if ($pk) {
                $this->get_records($configName, $resourceName, $recId, $request, false, true);
            } else {
                $entry = $entries[0];
                $attrs = isset($entry->attributes) ? (array) $entry->attributes : [];
                foreach ($attrs as $fld => $val) {
                    if ($val !== null && $val !== '') {
                        $request->add_filter_condition($fld, '=', $val);
                    }
                }
                $this->get_records($configName, $resourceName, null, $request, false, true);
            }
            return;
        }

        if ($pk) {
            $request->add_filter($pk . '><' . implode(';', $newRecIds));
        }

        $this->get_records($configName, $resourceName, null, $request, false, true);
    }

    function delete_single_record($configName, $resourceName, $recId)
    {
        $this->_init($configName);
        try {
            
            $this->recs->delete_by_id($resourceName, $recId);
            $def = $this->apiDm->get_config($resourceName);
            if(isset($def["hooks"]["delete"])) {
                $this->publishWebhookEvent($configName,$resourceName,$def["hooks"]["delete"],[
                    "event"=>"delete",
                    "recId" => $recId
                ]); 
            }
            HttpResp::no_content(204);
        }
        catch (Exception $exception) {
            HttpResp::json_out(HttpResp::exceptionHttpStatus($exception->getCode()), Document::from_exception($this->JsonApiDocOptions,$exception)->json_data());
        }
    }



}
