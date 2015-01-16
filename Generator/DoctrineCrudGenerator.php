<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sensio\Bundle\GeneratorBundle\Generator;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\Bundle\DoctrineBundle\Mapping\DisconnectedMetadataFactory;

/**
 * Generates a CRUD controller.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
class DoctrineCrudGenerator extends Generator
{
    protected $filesystem;
    protected $doctrine;
    protected $routePrefix;
    protected $routeNamePrefix;
    protected $bundle;
    protected $entity;
    protected $metadata;
    protected $format;
    protected $actions;

    /**
     * Constructor.
     *
     * @param Filesystem $filesystem A Filesystem instance
     */
    public function __construct(Filesystem $filesystem, $doctrine)
    {
        $this->filesystem  = $filesystem;
        $this->doctrine  = $doctrine;
    }

    /**
     * Generate the CRUD controller.
     *
     * @param BundleInterface   $bundle           A bundle object
     * @param string            $entity           The entity relative class name
     * @param ClassMetadataInfo $metadata         The entity class metadata
     * @param string            $format           The configuration format (xml, yaml, annotation)
     * @param string            $routePrefix      The route name prefix
     * @param array             $needWriteActions Wether or not to generate write actions
     *
     * @throws \RuntimeException
     */
    public function generate(BundleInterface $bundle, $entity, ClassMetadataInfo $metadata, $format, $routePrefix, $needWriteActions, $forceOverwrite)
    {
        $this->routePrefix = $routePrefix;
        $this->routeNamePrefix = str_replace('/', '_', $routePrefix);
        $this->actions = $needWriteActions ? array('index', 'show', 'new', 'edit', 'delete') : array('index', 'show');

        if (!count($metadata->identifier)) {
            throw new \RuntimeException('The CRUD generator does not support entity classes without any primary key.');
        }

        $this->entity   = $entity;
        $this->bundle   = $bundle;
        $this->metadata = $metadata;
        $this->fields = $metadata->fieldMappings;
        $this->mappings = array();
        
        foreach($metadata->getAssociationMappings() as $field => $meta) {
        
          switch($meta["type"]) {
          
            case ClassMetadataInfo::ONE_TO_ONE : 
            
              $cols = array();
              $isOwningSide = $meta['isOwningSide'];
              $oMeta = $meta;
              
              if(!$isOwningSide)
                $meta = $this->getEntityMetadata($meta["targetEntity"])[0]->getAssociationMappings()[$meta["mappedBy"]];
                
              foreach($meta["joinColumns"] as $col) {
              
                $from = $isOwningSide?$col["name"]:$col['referencedColumnName'];
                $to = $isOwningSide?$col["referencedColumnName"]:$col['name'];
                
                if($isOwningSide && !$metadata->isIdentifier($from))
                  unset($this->fields[$from]);
                  
                $cols[] = array(
                  'from' => $from, 
                  'to'   => $to
                );
                
              }
              
              $this->fields[$oMeta['fieldName']] = array(
                'type'    => '1to1', 
                'class'   => (new \ReflectionClass($oMeta["targetEntity"]))->getShortName(),
                'mapping' => $cols,
              );

              break;
              
              
            case ClassMetadataInfo::ONE_TO_MANY : 
            
              $fmeta = $this->getEntityMetadata($meta["targetEntity"])[0];
              $cols = array();
              
              foreach($fmeta->getAssociationMappings()[$meta["mappedBy"]]['joinColumns'] as $col)
                $cols[] = array(
                  'from' => $col['referencedColumnName'], 
                  'to'   => $col['name']
                );
              
              $this->fields[$meta['fieldName']] = array(
                'type'    => '1tom', 
                'class'   => (new \ReflectionClass($meta["targetEntity"]))->getShortName(),
                'mapping' => $cols,
              );
              
              break;
              
              
            case ClassMetadataInfo::MANY_TO_ONE : 
            
              $cols = array();
              
              foreach($meta["joinColumns"] as $col) {
              
                if(!$metadata->isIdentifier($col["name"]))
                  unset($this->fields[$col["name"]]);
                  
                $cols[] = array(
                  'from' => $col['name'], 
                  'to'   => $col['referencedColumnName']
                );
                
              }
              
              $this->fields[$meta['fieldName']] = array(
                'type'    => 'mto1', 
                'class'   => (new \ReflectionClass($meta["targetEntity"]))->getShortName(),
                'mapping' => $cols,
              );
              
              
              
              break;
              
              
            case ClassMetadataInfo::MANY_TO_MANY : 
              break;
          }
        }
        
        $this->setFormat($format);
        $this->generateControllerClass($forceOverwrite);

        $dir = sprintf('%s/Resources/views/Crud/%s', $this->bundle->getPath(), str_replace('\\', '/', $this->entity));

        if (!file_exists($dir)) {
            $this->filesystem->mkdir($dir, 0777);
        }

        $this->generateIndexView($dir);

        if (in_array('show', $this->actions)) {
            $this->generateShowView($dir);
        }

        if (in_array('new', $this->actions)) {
            $this->generateNewView($dir);
        }

        if (in_array('edit', $this->actions)) {
            $this->generateEditView($dir);
        }

        $this->generateTestClass();
        $this->generateConfiguration();
    }

    /**
     * Sets the configuration format.
     *
     * @param string $format The configuration format
     */
    private function setFormat($format)
    {
        switch ($format) {
            case 'yml':
            case 'xml':
            case 'php':
            case 'annotation':
                $this->format = $format;
                break;
            default:
                $this->format = 'yml';
                break;
        }
    }

    /**
     * Generates the routing configuration.
     *
     */
    protected function generateConfiguration()
    {
        if (!in_array($this->format, array('yml', 'xml', 'php'))) {
            return;
        }

        $target = sprintf(
            '%s/Resources/config/routing/%s.%s',
            $this->bundle->getPath(),
            strtolower(str_replace('\\', '_', $this->entity)),
            $this->format
        );

        $this->renderFile('crud/config/routing.'.$this->format.'.twig', $target, array(
            'actions'           => $this->actions,
            'route_prefix'      => $this->routePrefix,
            'route_name_prefix' => $this->routeNamePrefix,
            'bundle'            => $this->bundle->getName(),
            'entity'            => $this->entity,
        ));
    }

    /**
     * Generates the controller class only.
     *
     */
    protected function generateControllerClass($forceOverwrite)
    {
        $dir = $this->bundle->getPath();

        $parts = explode('\\', $this->entity);
        $entityClass = array_pop($parts);
        $entityNamespace = implode('\\', $parts);

        $target = sprintf(
            '%s/Controller/Crud/%s/Base%sController.php',
            $dir,
            str_replace('\\', '/', $entityNamespace),
            $entityClass
        );

        if (!$forceOverwrite && file_exists($target)) {
            throw new \RuntimeException('Unable to generate the controller as it already exists.');
        }

        $this->renderFile('crud/base.controller.php.twig', $target, array(
            'actions'           => $this->actions,
            'route_prefix'      => $this->routePrefix,
            'route_name_prefix' => $this->routeNamePrefix,
            'bundle'            => $this->bundle->getName(),
            'entity'            => $this->entity,
            'identifier'        => $this->metadata->identifier,
            'entity_class'      => $entityClass,
            'namespace'         => $this->bundle->getNamespace(),
            'entity_namespace'  => $entityNamespace,
            'format'            => $this->format,
        ));
        
        $target = sprintf(
            '%s/Controller/Crud/%s/%sController.php',
            $dir,
            str_replace('\\', '/', $entityNamespace),
            $entityClass
        );
        
        if (!file_exists($target))
          $this->renderFile('crud/child.controller.php.twig', $target, array(
              'actions'           => $this->actions,
              'route_prefix'      => $this->routePrefix,
              'route_name_prefix' => $this->routeNamePrefix,
              'bundle'            => $this->bundle->getName(),
              'entity'            => $this->entity,
              'identifier'        => $this->metadata->identifier,
              'entity_class'      => $entityClass,
              'namespace'         => $this->bundle->getNamespace(),
              'entity_namespace'  => $entityNamespace,
              'format'            => $this->format,
          ));
    }

    /**
     * Generates the functional test class only.
     *
     */
    protected function generateTestClass()
    {
        $parts = explode('\\', $this->entity);
        $entityClass = array_pop($parts);
        $entityNamespace = implode('\\', $parts);

        $dir    = $this->bundle->getPath().'/Tests/Controller';
        $target = $dir.'/'.str_replace('\\', '/', $entityNamespace).'/'.$entityClass.'ControllerTest.php';

        $this->renderFile('crud/tests/test.php.twig', $target, array(
            'route_prefix'      => $this->routePrefix,
            'route_name_prefix' => $this->routeNamePrefix,
            'entity'            => $this->entity,
            'identifier'        => $this->metadata->identifier,
            'bundle'            => $this->bundle->getName(),
            'entity_class'      => $entityClass,
            'namespace'         => $this->bundle->getNamespace(),
            'entity_namespace'  => $entityNamespace,
            'actions'           => $this->actions,
            'form_type_name'    => strtolower(str_replace('\\', '_', $this->bundle->getNamespace()).($parts ? '_' : '').implode('_', $parts).'_'.$entityClass),
        ));
    }

    /**
     * Generates the index.html.twig template in the final bundle.
     *
     * @param string $dir The path to the folder that hosts templates in the bundle
     */
    protected function generateIndexView($dir)
    {
        $this->renderFile('crud/views/index.html.twig.twig', $dir.'/index.html.twig', array(
            'bundle'            => $this->bundle->getName(),
            'entity'            => $this->entity,
            'identifier'        => $this->metadata->identifier,
            'fields'            => $this->fields,
            'mappings'          => $this->mappings,
            'actions'           => $this->actions,
            'record_actions'    => $this->getRecordActions(),
            'route_prefix'      => $this->routePrefix,
            'route_name_prefix' => $this->routeNamePrefix,
        ));
    }

    /**
     * Generates the show.html.twig template in the final bundle.
     *
     * @param string $dir The path to the folder that hosts templates in the bundle
     */
    protected function generateShowView($dir)
    {
        $this->renderFile('crud/views/show.html.twig.twig', $dir.'/show.html.twig', array(
            'bundle'            => $this->bundle->getName(),
            'entity'            => $this->entity,
            'identifier'        => $this->metadata->identifier,
            'fields'            => $this->fields,
            'mappings'          => $this->mappings,
            'actions'           => $this->actions,
            'route_prefix'      => $this->routePrefix,
            'route_name_prefix' => $this->routeNamePrefix,
        ));
    }

    /**
     * Generates the new.html.twig template in the final bundle.
     *
     * @param string $dir The path to the folder that hosts templates in the bundle
     */
    protected function generateNewView($dir)
    {
        $this->renderFile('crud/views/new.html.twig.twig', $dir.'/new.html.twig', array(
            'bundle'            => $this->bundle->getName(),
            'entity'            => $this->entity,
            'route_prefix'      => $this->routePrefix,
            'route_name_prefix' => $this->routeNamePrefix,
            'actions'           => $this->actions,
        ));
    }

    /**
     * Generates the edit.html.twig template in the final bundle.
     *
     * @param string $dir The path to the folder that hosts templates in the bundle
     */
    protected function generateEditView($dir)
    {
        $this->renderFile('crud/views/edit.html.twig.twig', $dir.'/edit.html.twig', array(
            'route_prefix'      => $this->routePrefix,
            'route_name_prefix' => $this->routeNamePrefix,
            'identifier'        => $this->metadata->identifier,
            'entity'            => $this->entity,
            'fields'            => $this->metadata->fieldMappings,
            'bundle'            => $this->bundle->getName(),
            'actions'           => $this->actions,
        ));
    }

    /**
     * Returns an array of record actions to generate (edit, show).
     *
     * @return array
     */
    protected function getRecordActions()
    {
        return array_filter($this->actions, function ($item) {
            return in_array($item, array('show', 'edit'));
        });
    }
    
    protected function getEntityMetadata($entity)
    {
        $factory = new DisconnectedMetadataFactory($this->doctrine);

        return $factory->getClassMetadata($entity)->getMetadata();
    }

}
