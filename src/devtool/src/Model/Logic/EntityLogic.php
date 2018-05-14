<?php

namespace Swoft\Devtool\Model\Logic;

use Swoft\Bean\Annotation\Bean;
use Swoft\Bean\Annotation\Inject;
use Swoft\Db\Types;
use Swoft\Devtool\FileGenerator;
use Swoft\Devtool\Model\Data\SchemaData;

/**
 * EntityLogic
 * @Bean()
 */
class EntityLogic
{
    /**
     * Default entity path
     */
    const DEFAULT_PATH = '@app/Models/Entity';

    /**
     * Default namespace
     */
    const DEFAULT_NAMESPACE = 'App\\Models\\Entity';

    /**
     * Default driver
     */
    const DEFAULT_DRIVER = 'mysql';

    /**
     * Default entity tpl file
     */
    const DEFAULT_TPL_ENTITY_FILE = 'entity';

    /**
     * Default property tpl file
     */
    const DEFAULT_TPL_PROPERTY_FILE = 'property';

    /**
     * Default setter tpl file
     */
    const DEFAULT_TPL_SETTER_FILE = 'setter';

    /**
     * Default getter tpl file
     */
    const DEFAULT_TPL_GETTER_FILE = 'getter';

    /**
     * @var SchemaData
     * @Inject()
     */
    private $schemaData;

    /**
     * @param array $params
     */
    public function generate(array $params)
    {
        list($db, $inc, $exc, $path, $namespace, $driver, $instance, $tablePrefix, $fieldPrefix, $tplDir, $isForce) = $params;
        $tableSchemas = $this->schemaData->getSchemaTableData($driver, $db, $inc, $exc, $tablePrefix);
        foreach ($tableSchemas as $tableSchema) {
            $this->generateClass($driver, $db, $instance, $tableSchema, $fieldPrefix, $path, $namespace, $tplDir, $isForce);
        }
    }

    /**
     * @param string $driver
     * @param string $db
     * @param string $instance
     * @param array  $tableSchema
     * @param string $fieldPrefix
     * @param string $path
     * @param string $namespace
     * @param string $tplDir
     * @param bool   $isForce
     */
    private function generateClass(string $driver, string $db, string $instance, array $tableSchema, string $fieldPrefix, string $path, string $namespace, string $tplDir, bool $isForce)
    {
        $classFile = alias($tplDir).self::DEFAULT_TPL_ENTITY_FILE;
        if(!file_exists($classFile)){
            $tplDir = alias('@devtool/res/templates/');
        }

        $mappingClass = $tableSchema['mapping'];
        $config       = [
            'tplFilename' => self::DEFAULT_TPL_ENTITY_FILE,
            'tplDir'      => $tplDir,
            'className'   => $mappingClass,
        ];

        $file = alias($path);
        $file .= sprintf('/%s.php', $mappingClass);

        $columnSchemas = $this->schemaData->getSchemaColumnsData($driver, $db, $tableSchema['name'], $fieldPrefix);

        $genSetters    = [];
        $genGetters    = [];
        $genProperties = [];
        $useRequired   = false;
        foreach ($columnSchemas as $columnSchema) {
            list($propertyCode, $required) = $this->generateProperties($columnSchema, $tplDir, $isForce);
            $genProperties[] = $propertyCode;
            if (!empty($required) && !$useRequired) {
                $useRequired = true;
            }

            $genSetters[] = $this->generateSetters($columnSchema, $tplDir);
            $genGetters[] = $this->generateGetters($columnSchema, $tplDir);
        }

        $setterStr   = implode("\n", $genSetters);
        $getterStr   = implode("\n", $genGetters);
        $propertyStr = implode("\n", $genProperties);
        $methodStr   = sprintf("%s\n\n%s", $setterStr, $getterStr);
        $usespace    = (!$useRequired) ? '' : 'use Swoft\Db\Bean\Annotation\Required;';
        $instance    = empty($instance)? '': sprintf('instance="%s"', $instance);

        $data = [
            'properties' => $propertyStr,
            'methods'    => $methodStr,
            'tableName'  => $tableSchema['name'],
            'entityName' => $mappingClass,
            'namespace'  => $namespace,
            'usespace'   => $usespace,
            'instance'   => $instance,
        ];
        $gen  = new FileGenerator($config);
        $gen->renderas($file, $data);
    }

    /**
     * @param array  $colSchema
     * @param string $tplDir
     * @param bool   $isForce
     *
     * @return array
     */
    private function generateProperties(array $colSchema, string $tplDir, bool $isForce): array
    {
        $classFile = alias($tplDir).self::DEFAULT_TPL_PROPERTY_FILE;
        if(!file_exists($classFile)){
            $tplDir = alias('@devtool/res/templates/');
        }

        $entityConfig = [
            'tplFilename' => self::DEFAULT_TPL_PROPERTY_FILE,
            'tplDir'      => $tplDir,
        ];

        // id
        $id = !empty($colSchema['key']) ? '* @Id()' : '';

        // required
        $isRequired = $colSchema['nullable'] === 'NO' && $colSchema['default'] === null;
        $required   = (!empty($colSchema['key']) || $isForce) ? false : $isRequired;
        $required   = ($required == true) ? '* @Required()' : '';

        // default
        $default = $this->transferDefaultType($colSchema['phpType'], $colSchema['key'], $colSchema['default']);
        $default = ($default !== null) ? sprintf(', default=%s', $default) : '';

        $data = [
            'type'          => $colSchema['phpType'],
            'propertyName'  => $colSchema['mappingVar'],
            'propertyValue' => '',
            'column'        => $colSchema['name'],
            'columnType'    => $colSchema['mappingType'],
            'default'       => $default,
            'required'      => $required,
            'id'            => $id,
        ];
        $gen          = new FileGenerator($entityConfig);
        $propertyCode = $gen->render($data);

        return [$propertyCode, $required];
    }

    /**
     * @param array  $colSchema
     * @param string $tplDir
     *
     * @return string
     */
    private function generateGetters(array $colSchema, string $tplDir): string
    {
        $classFile = alias($tplDir).self::DEFAULT_TPL_GETTER_FILE;
        if(!file_exists($classFile)){
            $tplDir = alias('@devtool/res/templates/');
        }

        $getterName = sprintf('get%s', ucfirst($colSchema['mappingName']));

        $config = [
            'tplFilename' => self::DEFAULT_TPL_GETTER_FILE,
            'tplDir'      => $tplDir,
        ];
        $data   = [
            'returnType' => $colSchema['phpType'],
            'methodName' => sprintf('get%s', $getterName),
            'property'   => $colSchema['mappingName'],
        ];

        $gen = new FileGenerator($config);

        return $gen->render($data);
    }

    /**
     * @param array  $colSchema
     * @param string $tplDir
     *
     * @return string
     */
    private function generateSetters(array $colSchema, string $tplDir): string
    {
        $classFile = alias($tplDir).self::DEFAULT_TPL_SETTER_FILE;
        if(!file_exists($classFile)){
            $tplDir = alias('@devtool/res/templates/');
        }

        $setterName = sprintf('set%s', ucfirst($colSchema['mappingName']));

        $config = [
            'tplFilename' => self::DEFAULT_TPL_SETTER_FILE,
            'tplDir'      => $tplDir,
        ];

        $data = [
            'type'       => $colSchema['phpType'],
            'methodName' => $setterName,
            'paramName'  => $colSchema['mappingVar'],
            'property'   => $colSchema['mappingName'],
        ];

        $gen = new FileGenerator($config);

        return $gen->render($data);
    }


    /**
     * @param string $type
     * @param string $primaryKey
     * @param mixed  $default
     *
     * @return array
     */
    private function transferDefaultType(string $type, string $primaryKey, $default): array
    {
        if (!empty($primaryKey)) {
            return [null, null];
        }

        if ($default === null) {
            $propertyValue = $this->getDefaultByType($type);

            return [null, $propertyValue];
        }

        $default = trim($default);
        switch ($type) {
            case Types::INT:
            case Types::NUMBER:
                $default = (int)$default;
                break;
            case Types::BOOL:
                $default = (bool)$default;
                break;
            case Types::FLOAT:
                $default = (float)$default;
                break;
            default:
                $default = sprintf('"%s"', $default);
                break;
        }

        return [$default, $default];
    }

    /**
     * @param string $type
     *
     * @return float|int|string
     */
    private function getDefaultByType(string $type)
    {
        $default = '';
        switch ($type) {
            case Types::INT:
            case Types::NUMBER:
                $default = 0;
                break;
            case Types::BOOL:
                $default = 0;
                break;
            case Types::FLOAT:
                $default = 0.0;
                break;
        }

        return $default;
    }
}