<?php

/**
 * This file is part of the PHPMongo package.
 *
 * (c) Dmytro Sokil <dmytro.sokil@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sokil\Mongo;

class Database
{
    /**
     *
     * @var \Sokil\Mongo\Client
     */
    private $client;

    /**
     * @var \MongoDB
     */
    private $mongoDB;

    /**
     * @var array map collection name to class
     */
    private $mapping = array();

    /**
     * @var array map regexp pattern of collection name to class
     */
    private $regexpMapping = array();

    /**
     * @var string if mapping not specified, use class prefix to create class path from collection name
     */
    private $classPrefix;

    /**
     * @var array pool of initialised collections
     */
    private $collectionPool = array();

    /**
     *
     * @var bool is collection pool enabled
     */
    private $collectionPoolEnabled = true;

    /**
     *
     * @var string default collection class
     */
    private $defultCollectionClass = '\Sokil\Mongo\Collection';

    /**
     *
     * @var string default gridFs class
     */
    private $defultGridFsClass = '\Sokil\Mongo\GridFS';

    public function __construct(Client $client, $database) {
        $this->client = $client;

        if($database instanceof \MongoDB) {
            $this->mongoDB = $database;
        } else {
            $this->mongoDB = $this->client->getMongoClient()->selectDB($database);
        }

    }

    /**
     *
     * @param string $username
     * @param string $password
     */
    public function authenticate($username, $password)
    {
        $this->mongoDB->authenticate($username, $password);
    }

    public function logout()
    {
        $this->executeCommand(array(
            'logout' => 1,
        ));
    }

    public function __get($name)
    {
        return $this->getCollection($name);
    }

    /**
     * @return string get name of database
     */
    public function getName()
    {
        return $this->mongoDB->__toString();
    }

    /**
     *
     * @return \MongoDB
     */
    public function getMongoDB()
    {
        return $this->mongoDB;
    }

    /**
     *
     * @return \Sokil\Mongo\Client
     */
    public function getClient()
    {
        return $this->client;
    }

    public function disableCollectionPool()
    {
        $this->collectionPoolEnabled = false;
        return $this;
    }

    public function enableCollectionPool()
    {
        $this->collectionPoolEnabled = true;
        return $this;
    }

    public function isCollectionPoolEnabled()
    {
        return $this->collectionPoolEnabled;
    }

    public function clearCollectionPool()
    {
        $this->collectionPool = array();
        return $this;
    }

    public function isCollectionPoolEmpty()
    {
        return !$this->collectionPool;
    }

    /**
     * Reset specified mapping
     *
     * @return \Sokil\Mongo\Client
     */
    public function resetMapping()
    {
        $this->mapping = array();
        $this->classPrefix = null;

        return $this;
    }

    /**
     * Map collection name to class
     *
     * @param string|array $name collection name or array like [collectionName => collectionClass, ...]
     * @param string|array|null $classDefinition if $name is string, then full class name or array with parameters, else omitted
     * @return \Sokil\Mongo\Client
     */
    public function map($name, $classDefinition = null)
    {
        // map collection to class
        if($classDefinition) {

            if(!is_array($classDefinition)) {
                $classDefinition = array('class' => $classDefinition);
            }

            if('/' !== substr($name, 0, 1)) {
                $this->mapping[$name] = $classDefinition;
            } else {
                $this->regexpMapping[$name] = $classDefinition;
            }

            return $this;
        }

        // map collections to classes
        if(is_array($name)) {
            foreach($name as $collectionName => $classDefinition) {
                $this->map($collectionName, $classDefinition);
            }
            return $this;
        }

        // define class prefix
        $this->classPrefix = rtrim($name, '\\');

        return $this;
    }

    /**
     * Get class name mapped to collection
     * @param string $name name of collection
     * @return string|array name of class or array of class definition
     */
    protected function getCollectionClassDefinition($name, $defaultClass = null)
    {
        if(!$defaultClass) {
            $defaultClass = $this->defultCollectionClass;
        }

        if(isset($this->mapping[$name])) {
            $classDefinition = $this->mapping[$name];
            if(empty($classDefinition['class'])) {
                $classDefinition['class'] = $defaultClass;
            }
        } elseif($this->regexpMapping) {
            foreach($this->regexpMapping as $collectionNamePattern => $regexpMappingClassDefinition) {
                if(empty($regexpMappingClassDefinition['class'])) {
                    $regexpMappingClassDefinition['class'] = $defaultClass;
                }

                if(preg_match($collectionNamePattern, $name, $matches)) {
                    $classDefinition = $regexpMappingClassDefinition;
                    $classDefinition['regexp'] = $matches;
                    break;
                }
            }
        }

        if(!isset($classDefinition)) {
            if($this->classPrefix) {
                $classDefinition = array(
                    'class' => $this->classPrefix . '\\' . implode('\\', array_map('ucfirst', explode('.', $name)))
                );
            } else {
                $classDefinition = array(
                    'class' => $defaultClass,
                );
            }
        }

        if(!class_exists($classDefinition['class'])) {
            throw new Exception('Class ' . $classDefinition['class'] . ' not found while map collection name to class');
        }

        return $classDefinition;
    }

    /**
     * Get class name mapped to collection
     * @param string $name name of collection
     * @return string name of class
     */
    protected function getGridFSClassDefinition($name)
    {
        return $this->getCollectionClassDefinition($name, $this->defultGridFsClass);
    }

    /**
     * Create collection
     *
     * @param string $name name of collection
     * @param array|null $options array of options
     * @return \Sokil\Mongo\Collection
     * @throws \Sokil\Mongo\Exception
     */
    public function createCollection($name, array $options = null)
    {
        $classDefinition = $this->getCollectionClassDefinition($name);
        $className = $classDefinition['class'];

        $options = $options + $classDefinition;

        $mongoCollection = $this->getMongoDB()->createCollection($name, $options);

        return new $className(
            $this,
            $mongoCollection,
            $options
        );
    }

    /**
     *
     * @param string $name name of collection
     * @param int $maxElements The maximum number of elements to store in the collection.
     * @param int $size Size in bytes.
     * @return \Sokil\Mongo\Collection
     * @throws Exception
     */
    public function createCappedCollection($name, $maxElements, $size)
    {
        $options = array(
            'capped'    => true,
            'size'      => (int) $size,
            'max'       => (int) $maxElements,
        );

        if(!$options['size'] && !$options['max']) {
            throw new Exception('Size or number of elements must be defined');
        }

        return $this->createCollection($name, $options);
    }

    /**
     *
     * @param string $name name of collection
     * @return \Sokil\Mongo\Collection
     * @throws \Sokil\Mongo\Exception
     */
    public function getCollection($name) {

        // return from pool
        if($this->collectionPoolEnabled && isset($this->collectionPool[$name])) {
            return $this->collectionPool[$name];
        }

        // no object in pool - init new
        $classDefinition = $this->getCollectionClassDefinition($name);
        $className = $classDefinition['class'];
        unset($classDefinition['class']);

        // create collection class
        $collection = new $className($this, $name, $classDefinition);
        if(!$collection instanceof \Sokil\Mongo\Collection) {
            throw new Exception('Must be Collection');
        }

        // store to pool
        if($this->collectionPoolEnabled) {
            $this->collectionPool[$name] = $collection;
        }

        // return
        return $collection;
    }

    /**
     * Get instance of GridFS
     *
     * @param string $prefix prefix of files and chunks collection
     * @return \Sokil\Mongo\GridFS
     * @throws \Sokil\Mongo\Exception
     */
    public function getGridFS($prefix = 'fs')
    {
        // get from cache if enabled
        if($this->collectionPoolEnabled && isset($this->collectionPool[$prefix])) {
            return $this->collectionPool[$prefix];
        }

        // no object in cache - init new
        $classDefinition = $this->getGridFSClassDefinition($prefix);
        $className = $classDefinition['class'];

        $gridFS = new $className($this, $prefix, $classDefinition);
        if(!$gridFS instanceof GridFS) {
            throw new Exception('Must be GridFS');
        }

        // store to cache
        if($this->collectionPoolEnabled) {
            $this->collectionPool[$prefix] = $gridFS;
        }

        // return
        return $gridFS;

    }

    /**
     *
     * @param string $channel name of channel
     * @return \Sokil\Mongo\Queue
     */
    public function getQueue($channel)
    {
        return new Queue($this, $channel);
    }

    /**
     * Get cache
     *
     * @param string $namespace
     * @return \Sokil\Mongo\Cache
     */
    public function getCache($namespace)
    {
        return new Cache($this, $namespace);
    }

    public function readPrimaryOnly()
    {
        $this->mongoDB->setReadPreference(\MongoClient::RP_PRIMARY);
        return $this;
    }

    public function readPrimaryPreferred(array $tags = null)
    {
        $this->mongoDB->setReadPreference(\MongoClient::RP_PRIMARY_PREFERRED, $tags);
        return $this;
    }

    public function readSecondaryOnly(array $tags = null)
    {
        $this->mongoDB->setReadPreference(\MongoClient::RP_SECONDARY, $tags);
        return $this;
    }

    public function readSecondaryPreferred(array $tags = null)
    {
        $this->mongoDB->setReadPreference(\MongoClient::RP_SECONDARY_PREFERRED, $tags);
        return $this;
    }

    public function readNearest(array $tags = null)
    {
        $this->mongoDB->setReadPreference(\MongoClient::RP_NEAREST, $tags);
        return $this;
    }

    public function getReadPreference()
    {
        return $this->mongoDB->getReadPreference();
    }

    /**
     * Define write concern.
     * May be used only if mongo extension version >=1.5
     *
     * @param string|integer $w write concern
     * @param int $timeout timeout in milliseconds
     * @return \Sokil\Mongo\Database
     * @throws \Sokil\Mongo\Exception
     */
    public function setWriteConcern($w, $timeout = 10000)
    {
        if(!$this->mongoDB->setWriteConcern($w, (int) $timeout)) {
            throw new Exception('Error setting write concern');
        }

        return $this;
    }

    /**
     * Define unacknowledged write concern.
     * May be used only if mongo extension version >=1.5
     *
     * @param int $timeout timeout in milliseconds
     * @return \Sokil\Mongo\Database
     */
    public function setUnacknowledgedWriteConcern($timeout = 10000)
    {
        $this->setWriteConcern(0, (int) $timeout);
        return $this;
    }

    /**
     * Define majority write concern.
     * May be used only if mongo extension version >=1.5
     *
     * @param int $timeout timeout in milliseconds
     * @return \Sokil\Mongo\Database
     */
    public function setMajorityWriteConcern($timeout = 10000)
    {
        $this->setWriteConcern('majority', (int) $timeout);
        return $this;
    }

    /**
     * Get current write concern
     * May be used only if mongo extension version >=1.5
     *
     * @return mixed
     */
    public function getWriteConcern()
    {
        return $this->mongoDB->getWriteConcern();
    }

    /**
     * Execute Mongo command
     *
     * @param array $command
     * @param array $options
     * @return array
     */
    public function executeCommand(array $command, array $options = array())
    {
        return $this->getMongoDB()->command($command, $options);
    }

    public function executeJS($code, array $args = array())
    {
        $response = $this->getMongoDB()->execute($code, $args);
        if($response['ok'] == 1.0) {
            return $response['retval'];
        } else {
            throw new Exception('Error #' . $response['code'] . ': ' . $response['errmsg'], $response['code']);
        }
    }

    public function stats()
    {
        return $this->executeCommand(array(
            'dbstats' => 1,
        ));
    }

    public function getProfilerParams()
    {
        return $this->executeCommand(array(
            'profile'   => -1,
        ));
    }

    public function getProfilerLevel()
    {
        $params = $this->getProfilerParams();
        return $params['was'];
    }

    public function getProfilerSlowMs()
    {
        $params = $this->getProfilerParams();
        return $params['slowms'];
    }

    public function disableProfiler()
    {
        return $this->executeCommand(array(
            'profile'   => 0,
        ));
    }

    public function profileSlowQueries($slowms = 100)
    {
        return $this->executeCommand(array(
            'profile'   => 1,
            'slowms'    => (int) $slowms
        ));
    }

    public function profileAllQueries($slowms = null)
    {
        $command = array(
            'profile'   => 2,
        );

        if($slowms) {
            $command['slowms'] = (int) $slowms;
        }

        return $this->executeCommand($command);
    }

    /**
     *
     * @return \Sokil\Mongo\QueryBuilder
     */
    public function findProfilerRows()
    {
        return $this
            ->getCollection('system.profile')
            ->find()
            ->asArray();
    }
}
