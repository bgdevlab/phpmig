<?php
/**
 * Created by PhpStorm.
 * User: Rocky
 * Date: 7/08/14
 * Time: 4:22 PM
 */

namespace Phpmig\Adapter\PDO\Util\Pgsql;

/**
 * Class DropUtility
 * @package Phpmig\Migration\Util
 *
 * Use this class if there is any difficulty in running drop command for up and down migration.
 * For example If your database is postgres 8.1.x then you cannot use 'DROP FUNCTION IF EXISTS...' command.
 */

class DropUtility {

    const INTEGER = 23;
    const VARCHAR = 1043;
    const TIMESTAMPTZ = 1184;

    private $argTypeMap = array();

    /**
     * @var \PDO
     */
    private $pdo;

    public function __construct(\PDO $pdo){
        $this->pdo = $pdo;

        $this->argTypeMap['INTEGER'] = DropUtility::INTEGER;
        $this->argTypeMap['VARCHAR'] = DropUtility::VARCHAR;
        $this->argTypeMap['TIMESTAMPTZ'] = DropUtility::TIMESTAMPTZ;
    }

    protected function getSchemaOid($schemaName){
        $queryForSchema = 'SELECT *, pg_namespace.oid FROM pg_namespace WHERE nspname = \'' . $schemaName . '\';';
        $result = $this->pdo->query($queryForSchema);
        $schemaInfo = $result->fetch(\PDO::FETCH_ASSOC);

        if(!empty($result)){
            return $schemaInfo['oid'];
        }
        else{
            throw new \Exception('No matching schema found...');
        }
    }

    protected function mapArguments($arguments = array()){

        $argNames = '';
        $argTypes = '';
        $fullArgString = '';

        foreach($arguments['parameters'] as $argName => $argType){

            $argNames .= $argName . ',';

            $argTypeNumber = $this->argTypeMap[strtoupper($argType)];
            $argTypes .= $argTypeNumber. ' ';

            $fullArgString .= $argName . ' ' . $argType . ',';
        }

        return array(
            'argTypes' => substr($argTypes, 0 , -1),
            'argNames' => '{' . substr($argNames, 0 , -1) . '}'
        );
    }

    protected function assembleFunctionName($functionName, $arguments = array()){
        $parameter = '(';

        foreach($arguments['parameters'] as $argName => $argType){

            $parameter .= $argName . ' ' . $argType . ',';
        }

        $parameter = substr($parameter, 0 , -1) . ')';

        return '"' . $arguments['schemaName'] . '"' . '."' . $functionName . '"' . $parameter;
    }

    /**
     * @param $functionName
     * @param array $arguments
     * 4argumetn array format exxmple :
     * array(
            'schemaName' => 'public',
            'parameters' => array(
            'personcode' => 'INTEGER',
            'deviceid' => 'INTEGER',
            'cardserialnumber' => 'VARCHAR'
       )
     * @return string
     */
    public function dropFunction($functionName, $arguments = array()){
        $conditionArray = $this->mapArguments($arguments);
        $schemaOid = $this->getSchemaOid($arguments['schemaName']);

        $queryForFunctionCheck = 'SELECT * FROM pg_proc where proname= \'' . $functionName . '\'';
        $queryForFunctionCheck .= ' AND proargnames = \'' . $conditionArray['argNames'] . '\'';
        $queryForFunctionCheck .= ' AND proargtypes = \'' . $conditionArray['argTypes'] . '\'';
        $queryForFunctionCheck .= ' AND pronamespace = ' . $schemaOid;

        $query = $this->pdo->query($queryForFunctionCheck);
        $result = $query->fetch(\PDO::FETCH_ASSOC);

        if(!empty($result)) {
            return 'DROP FUNCTION ' . $this->assembleFunctionName($functionName, $arguments);
        }

        return '';
    }
} 