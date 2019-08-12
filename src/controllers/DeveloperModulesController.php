<?php
/**
 * Created by PhpStorm.
 * User: User
 * Date: 4/25/2019
 * Time: 9:28 PM
 */

namespace crocodicstudio\crudbooster\controllers;

use crocodicstudio\crudbooster\exceptions\CBValidationException;
use crocodicstudio\crudbooster\helpers\ModuleGenerator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;

class DeveloperModulesController extends Controller
{

    private $view = "crudbooster::dev_layouts.modules.modules";

    public function __construct()
    {
        view()->share(['page_title'=>'Module Manager']);
    }


    public function getIndex() {
        $data = [];
        $data['result'] = DB::table("cb_modules")->get();
        return view($this->view.'.index',$data);
    }

    public function getAdd() {
        $data = [];
        $data['tables'] = cb()->listAllTable();
        $data['module'] = (request('modules_id'))?cb()->find("cb_modules", request("modules_id")):null;
        return view($this->view.'.add', $data);
    }

    public function getTables()
    {
        return response()->json(cb()->listAllTable());
    }

    public function getAllColumn($table) {
        $data = cb()->listAllColumns($table);
        $pk = cb()->findPrimaryKey($table);
        $result = [];
        foreach($data as $item) {

            if(Str::contains(strtolower($item),["title","name","label"])) {
                $display = true;
            }else{
                $display = false;
            }

            $result[] = [
                "column"=>$item,
                "primary_key"=>($pk==$item)?true:false,
                "display"=>$display
            ];
        }
        return response()->json($result);
    }

    public function getColumns($table)
    {
        if(request("modules_id")) {
            $module = cb()->find("cb_modules",request("modules_id"));
            if($module->last_column_build) {
                return response()->json(json_decode($module->last_column_build));
            }
        }

        $columns = cb()->listAllColumns($table);
        $pk = cb()->findPrimaryKey($table);
        $result = [];
        foreach($columns as $column) {
            if($column != $pk) {

                // Check if any relation table candidate
                $optionTable = "";
                if(Str::substr(strtolower($column),-3,3) == "_id") {
                    $relationTable = Str::substr($column,0,-3);
                    if(Schema::hasTable($relationTable)) {
                        $optionTable = $relationTable;
                    }
                }elseif (Str::substr(strtolower($column),0,3) == "id_") {
                    $relationTable = Str::substr($column,3);
                    if(Schema::hasTable($relationTable)) {
                        $optionTable = $relationTable;
                    }
                }

                $label = trim(Str::title(str_replace(["id_","_id","_"]," ",$column)));
                $label = Str::singular($label);
                $type = "text";

                if(Str::contains(strtolower($label),["photo","image","picture","gambar"])) {
                    $type = "image";
                }elseif (Str::contains(strtolower($label),["email","mail"])) {
                    $type = "email";
                }elseif (Str::contains(strtolower($label),["description","content","detail"])) {
                    $type =  "wysiwyg";
                }elseif (Str::contains(strtolower($label),["price","money","grand_total","tax"])) {
                    $type = "money";
                }elseif (Str::contains(strtolower($label),["quantity","qty","total","phone","telp"])) {
                    $type = "number";
                }elseif (Str::contains(strtolower($label),["date"])) {
                    $type = "date";
                }

                if (Str::substr(strtolower($column),-3,3) == "_id") {
                    $type = "select_table";
                } elseif (Str::substr($column, -3, 3) == "_at") {
                    $type = "datetime";
                } elseif (Str::substr($column,0, 3) == "id_") {
                    $type = "select_table";
                }

                $result[] = [
                    'column_label'=>$label,
                    'column_field'=> $column,
                    'column_type'=>$type,
                    'column_option_table'=>$optionTable,
                    'column_option_value'=> "",
                    'column_option_display'=> "",
                    'column_option_sql_condition'=> "",
                    'column_options'=> [],
                    'column_sql_query'=> "",
                    'column_help'=> "",
                    'column_mandatory'=> "on",
                    'column_browse'=> "on",
                    'column_detail'=> "on",
                    'column_edit'=> "on",
                    'column_add'=> "on",
                    'listTableColumns'=> []
                ];
            }
        }
        return response()->json($result);
    }

    private function findComposer()
    {
        if (file_exists(getcwd().'/composer.phar')) {
            return '"'.PHP_BINARY.'" '.getcwd().'/composer.phar';
        }

        return 'composer';
    }

    private function composingAutoLoad()
    {
        $composer = $this->findComposer();
        $process = new Process($composer.' dump-autoload');
        $process->setWorkingDirectory(base_path())->run();
    }

    public function postCreateMigration()
    {
        try {
            cb()->validation(['table_name','structures']);

            $tableName = request("table_name");

            if(!Schema::hasTable($tableName)) {
                $filenameMigration = date("Y_m_d_His")."_".$tableName.".php";
                $createTemplate = file_get_contents(base_path("vendor/laravel/framework/src/Illuminate/Database/Migrations/stubs/create.stub"));
                $className = Str::studly($tableName);
                $createTemplate = str_replace("DummyClass", $className, $createTemplate);
                $createTemplate = str_replace("DummyTable", $tableName, $createTemplate);
                $createTemplate = str_replace("\$table->increments('id');","",$createTemplate);

                $structureItems = "";

                if(request("timestamp")) {
                    $structureItems .= "\$table->timestamps();\n\t\t\t";
                }

                if(request("soft_deletes")) {
                    $structureItems .= "\$table->softDeletes();\n\t\t\t";
                }

                foreach(request("structures") as $item) {

                    $nullable = "";

                    if(!in_array($item['type_data'],["bigIncrements","increments","mediumIncrements","smallIncrements"])) {
                        $nullable = "->nullable()";
                    }

                    if($item['length']) {
                        $structureItems .= "\$table->".$item['type_data']."('".$item['field_name']."', ".$item['length'].")$nullable;\n\t\t\t";
                    }else{
                        $structureItems .= "\$table->".$item['type_data']."('".$item['field_name']."')$nullable;\n\t\t\t";
                    }
                }
                $createTemplate = str_replace("\$table->timestamps();", $structureItems, $createTemplate);

                // Put File onto the migration folders
                file_put_contents(database_path("migrations/".$filenameMigration), $createTemplate);

                // Composing
                $this->composingAutoLoad();

                // Migrate
                Artisan::call("migrate");
            } else {
                throw new \Exception("The table $tableName has already exists!");
            }

        } catch (CBValidationException $e) {
            return response()->json(['status'=>false,'message'=>$e->getMessage()]);
        } catch (\Exception $e) {
            return response()->json(['status'=>false,'message'=>$e->getMessage()]);
        }

        return response()->json(['status'=>true,'message'=>'Migration successfully!']);
    }

    public function postCheckExistModule()
    {
        try {
            cb()->validation(['name', 'table']);

            if(DB::table("cb_modules")->where("table_name",request("table"))->where("name",request("name"))->count()) {
                return response()->json(['status'=>true]);
            }

        } catch (CBValidationException $e) {
            return response()->json(['status'=>false,'message'=>$e->getMessage()]);
        }

        return response()->json(['status'=>false]);
    }

    public function postAddSave() {

        try {
            cb()->validation(['name', 'table','icon','columns']);

            $module = (new ModuleGenerator(request('table'), request('name'), request('icon'), request("columns"), request("rebuild")))->make();

        } catch (CBValidationException $e) {
            return response()->json(['status'=>false,'message'=>$e->getMessage()]);
        }

        return response()->json(['status'=>true, 'message'=>'Please remember that you can still modify the structure by edit the controller file '.$module['controller'].' :)']);
    }

    public function getEdit($id) {
        $data = [];
        $data['row'] = cb()->find("cb_modules", $id);
        return view($this->view.".edit", $data);
    }

    public function postEditSave($id) {
        try {
            cb()->validation(['name', 'icon']);

            cb()->updateCompact("cb_modules", $id, ['name','icon']);

            return cb()->redirect(route("DeveloperModulesControllerGetIndex"),"Module has been updated!","success");

        } catch (CBValidationException $e) {
            return cb()->redirectBack($e->getMessage());
        }
    }

    public function getDelete($id) {
        $module = cb()->find("cb_modules",$id);
        @unlink(app_path("Http/Controllers/".$module->controller.".php"));
        DB::table("cb_modules")->where("id", $id)->delete();
        DB::table("cb_menus")->where("cb_modules_id", $id)->delete();
        return cb()->redirectBack("The module has been deleted!","success");
    }

    public function getDeleteSoft($id) {
        $module = cb()->find("cb_modules",$id);
        DB::table("cb_modules")->where("id", $id)->delete();
        DB::table("cb_menus")->where("cb_modules_id", $id)->delete();
        return cb()->redirectBack("The module has been deleted!","success");
    }

}