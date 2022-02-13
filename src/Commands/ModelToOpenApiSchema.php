<?php

/**
 * This file is part of auto-comment-for-l5-swagger
 *
 */

namespace AutoCommentForL5Swagger\Commands;

use AutoCommentForL5Swagger\Commands\Traits\CommentFormatter;
use AutoCommentForL5Swagger\Libs\EmptyExample;
use Barryvdh\LaravelIdeHelper\Console\ModelsCommand;
use ReflectionClass;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class ModelToOpenApiSchema extends ModelsCommand
{
    use CommentFormatter;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $name = 'openapi:create-model-to-schema';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create PHPSwagger schema file for models';

    protected $config_root = 'auto-comment-for-l5-swagger.documentations.';

    protected $schema_path = '';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $type = $this->argument('type') ?? 'default';
        $this->config_root .= $type . '.';
        $this->schema_path = $this->laravel['config']->get($this->config_root . 'schema_path', $path = $this->laravel['path'] . '/Schemas/');

        if (!is_dir($this->schema_path)) {
            $this->error('Please create dir.:' . $this->schema_path);

            return -1;
        }

        $this->input->setOption('write', true);

        return parent::handle();
    }

    /**
     * Get the stub file for the generator.
     *
     * @param $name
     * @return string
     */
    protected function getStub($name): string
    {
        return __DIR__ . '/stubs/' . $name . '.stub';
    }

    /** @noinspection SlowArrayOperationsInLoopInspection */
    protected function generateDocs($loadModels, $ignore = '')
    {
        $path = rtrim($this->schema_path, '/') . '/';

        $output = '';

        $hasDoctrine = interface_exists('Doctrine\DBAL\Driver');

        if (empty($loadModels)) {
            $models = $this->loadModels();
        } else {
            $models = [];
            foreach ($loadModels as $model) {
                $models = array_merge($models, explode(',', $model));
            }
        }

        $ignore = array_merge(
            explode(',', $ignore),
            $this->laravel['config']->get($this->config_root . 'ignored_models', [])
        );

        foreach ($models as $name) {
            if (in_array($name, $ignore)) {
                if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                    $this->comment("Ignoring model '${name}'");
                }

                continue;
            }
            $this->properties = [];
            $this->methods = [];
            if (class_exists($name)) {
                try {
                    // handle abstract classes, interfaces, ...
                    $reflectionClass = new ReflectionClass($name);

                    if (!$reflectionClass->isSubclassOf('Illuminate\Database\Eloquent\Model')) {
                        continue;
                    }

                    $this->comment("Loading model '${name}'", OutputInterface::VERBOSITY_VERBOSE);

                    if (!$reflectionClass->IsInstantiable()) {
                        // ignore abstract class or interface
                        continue;
                    }

                    /**
                     * @var \Illuminate\Database\Eloquent\Model $model
                     */
                    $model = $this->laravel->make($name);

                    if ($hasDoctrine) {
                        $this->getPropertiesFromTable($model);
                    }

                    if (method_exists($model, 'getCasts')) {
                        $this->castPropertiesType($model);
                    }

                    $this->getPropertiesFromMethods($model);
                    $this->getSoftDeleteMethods($model);
                    $this->getCollectionMethods($model);
                    $this->getFactoryMethods($model);

                    $this->runModelHooks($model);

                    $hidden = $reflectionClass->getProperty('hidden');
                    $hidden->setAccessible(true);
                    $hidden = $hidden->getValue(new $name);

                    $OARequired = [];
                    $properties = '';
                    $properties_doc_comments = '';
                    $ai = '';
                    foreach ($this->properties as $VariableName => $property) {
                        $TypeProperty = $property['type'];
                        if (strpos($TypeProperty, "\\") !== false) {
                            continue;
                        }

                        if ($property['read'] === false) {
                            continue;
                        }

                        if (array_search($VariableName, $hidden) !== false) {
                            continue;
                        }

                        $TypeProperty = str_replace(
                            ['timestamp', 'date', 'datetime', 'int', 'bool', 'boolbool', '|'],
                            ['string', 'string', 'string', 'integer', 'boolean', 'bool', ','],
                            $TypeProperty
                        );

                        $_TypeProperty = $TypeProperty;
                        if (strpos($_TypeProperty, 'integer') !== false) {
                            $TypeProperty = '"integer"';
                        } elseif (strpos($_TypeProperty, 'double') !== false) {
                            $TypeProperty = '"number"';
                        } elseif (strpos($_TypeProperty, 'float') !== false) {
                            $TypeProperty = '"number"';
                        } elseif (strpos($_TypeProperty, 'string') !== false) {
                            $TypeProperty = '"string"';
                        } elseif (strpos($_TypeProperty, 'boolean') !== false) {
                            $TypeProperty = '"boolean"';
                        } elseif (strpos($_TypeProperty, 'array') !== false) {
                            $TypeProperty = '"array"';
                        } elseif (strpos($_TypeProperty, 'Carbon') !== false) {
                            $TypeProperty = '"string"';
                        } else {
                            $TypeProperty = '"string"';
                        }

                        if (strpos($_TypeProperty, 'null')) {
                            $TypeProperty .= ',nullable=true';
                        }

                        $api_example_property_name = $this->laravel['config']->get($this->config_root . 'api_example_property_name', 'api_example_property_name');
                        $example = (array)$model->$api_example_property_name;
                        $OARequired[] = '"' . $VariableName . '"';

                        if ($model->incrementing && $model->getKeyName() === $VariableName) {
                            $ai = str_replace(['DOC_COMMENT', 'VariableName'], [trim('@var ' . $property['type']), $VariableName], file_get_contents($this->getStub('oa_property')));

                            continue;
                        }

                        $properties_doc_comment = $this->createPropertyAnnotation($VariableName, $TypeProperty, $property['comment'], array_key_exists($VariableName, $example) ? $example[$VariableName] : new EmptyExample());
                        $properties_doc_comments .= $properties_doc_comment;
                        $properties .= str_replace(['DOC_COMMENT', 'VariableName'], [trim('@var ' . $property['type']), $VariableName], file_get_contents($this->getStub('oa_property')));
                    }

                    $schema = file_get_contents($this->getStub('oa_schema'));

                    $MainClassAnnotation = $this->createMainClassAnnotation($reflectionClass->getShortName(), join(',', $OARequired), $properties_doc_comments);

                    $ListClassAnnotation = $this->createListClassAnnotation($reflectionClass->getShortName(), $model->incrementing ? $model->getKeyName() : null);

                    $schema = str_replace(
                        ['// properties //', '// ai_properties //', 'ClassName', 'OARequired', '__Namespaces__', 'MAIN_CLASS_ANNOTATION', 'LIST_CLASS_ANNOTATION'],
                        [$properties, $ai, $reflectionClass->getShortName(), join(',', $OARequired), $this->laravel['config']->get($this->config_root . 'schema_name_space'), $this->commentFormatter(trim($MainClassAnnotation)), $this->commentFormatter(trim($ListClassAnnotation))],
                        $schema
                    );

                    file_put_contents($path . $reflectionClass->getShortName() . '.php', $schema);

                    // $output .= $this->createPhpDocs($name);
                    $ignore[] = $name;
                    $this->nullableColumns = [];
                } catch (Throwable $e) {
                    $this->error('Exception: ' . $e->getMessage() .
                        "\nCould not analyze class ${name}.\n\nTrace:\n" .
                        $e->getTraceAsString());
                }
            }
        }

        if (!$hasDoctrine) {
            $this->error(
                'Warning: `"doctrine/dbal": "~2.3"` is required to load database information. ' .
                'Please require that in your composer.json and run `composer update`.'
            );
        }

        return $output;
    }

    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return array_merge(
            [
                ['type', InputArgument::OPTIONAL, 'Config type to be used', 'default'],
            ],
            parent::getArguments(),
        );
    }

    protected function createPropertyAnnotation(string $name, string $TypeProperty, string $TypeDescription, $example): string
    {
        if ($example instanceof EmptyExample) {
            $comment = <<<COMMENT
@OA\\Property(property="{$name}", type={$TypeProperty},description="{$TypeDescription}"),

COMMENT;
        } else {
            $example = json_encode($example);
            $comment = <<<COMMENT
@OA\\Property(property="{$name}", type={$TypeProperty},description="{$TypeDescription}",example={$example}),

COMMENT;
        }

        return $comment;
    }

    protected function createMainClassAnnotation($schema_name, $required, $all_of = ''): string
    {
        return <<<COMMENT
@OA\\Schema(
    schema="Create{$schema_name}",
    required={{$required}},
    type="object",
    {$all_of}
)

COMMENT;
    }

    protected function createListClassAnnotation($schema_name, $ai = 'id'): string
    {
        if ($ai) {
            $comment = <<<COMMENT
@OA\\Schema(
  schema="{$schema_name}",
  type="object",
  allOf={
      @OA\\Schema(ref="#/components/schemas/Create{$schema_name}"),
      @OA\\Schema(
          required={"{$ai}"},
          @OA\\Property(property="{$ai}", format="int64", type="integer")
      )
  }
)

COMMENT;
        } else {
            $comment = <<<COMMENT
@OA\\Schema(
  schema="{$schema_name}",
  type="object",
  allOf={
      @OA\\Schema(ref="#/components/schemas/Create{$schema_name}"),
  }
)

COMMENT;
        }

        $comment .= <<<COMMENT
@OA\\Schema(
  schema="{$schema_name}PagenateLink",
  type="object",
  allOf={
    @OA\\Schema(
         required={"url", "label", "active"},
         @OA\\Property(property="label", type="string"),
         @OA\\Property(property="url", type="string"),
         @OA\\Property(property="active", type="boolean"),
    )
  }
)

@OA\\Schema(
     schema="{$schema_name}Pagenate",
     type="object",
     allOf={
         @OA\\Schema(
             required={"data","current_page", "from", "last_page", "per_page", "to", "total", "first_page_url", "last_page_url", "path", "prev_page_url", "links"},
             @OA\\Property(
                 property="data",
                 type="array",
                 @OA\\Items(
                     ref="#/components/schemas/{$schema_name}"
                 )
             ),
             @OA\\Property(property="current_page", format="int64", type="integer"),
             @OA\\Property(property="from", format="int64", type="integer"),
             @OA\\Property(property="last_page", format="int64", type="integer"),
             @OA\\Property(property="per_page", format="int64", type="integer"),
             @OA\\Property(property="to", format="int64", type="integer"),
             @OA\\Property(property="total", format="int64", type="integer"),
             @OA\\Property(property="first_page_url", type="string"),
             @OA\\Property(property="last_page_url", type="string"),
             @OA\\Property(property="next_page_url", type="string"),
             @OA\\Property(property="path", type="string"),
             @OA\\Property(property="prev_page_url", type="string"),

             @OA\\Property(
                 property="links",
                 type="array",
                 @OA\\Items(
                     ref="#/components/schemas/{$schema_name}PagenateLink"
                 )
             ),
         ),
     }
)

@OA\\Schema(
     schema="{$schema_name}Many",
     type="array",
     @OA\\Items(
         ref="#/components/schemas/{$schema_name}"
     )
)
COMMENT;

        return $comment;
    }
}
