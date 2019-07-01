<?php
/*
 * Copyright 2018 Jérôme Gasperi
 *
 * Licensed under the Apache License, version 2.0 (the "License");
 * You may not use this file except in compliance with the License.
 * You may obtain a copy of the License at:
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS, WITHOUT
 * WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied. See the
 * License for the specific language governing permissions and limitations
 * under the License.
 */

/**
 * RESTo PostgreSQL collections functions
 */
class CollectionsFunctions
{
    private $dbDriver = null;

    /**
     * Constructor
     *
     * @param RestoDatabaseDriver $dbDriver
     * @throws Exception
     */
    public function __construct($dbDriver)
    {
        $this->dbDriver = $dbDriver;
    }

    /**
     * Get description for collection
     *
     * @param string $name
     * @return array
     * @throws Exception
     */
    public function getCollectionDescription($name)
    {
        
        // Get Opensearch description
        $osDescriptions = $this->getOSDescriptions($name);
        $collection = null;
        $results = $this->dbDriver->pQuery('SELECT name, visibility, owner, model, mapping, licenseid FROM resto.collection WHERE normalize(name)=normalize($1)', array($name));
        while ($rowDescription = pg_fetch_assoc($results)) {
            $collection = array_merge(
                FormatUtil::collectionDescription($rowDescription),
                array('osDescription' => $osDescriptions[$name])
            );
        }
        return $collection;
    }

    /**
     * Get description of all collections including facets
     *
     * @param array $visibilities
     * @return array
     * @throws Exception
     */
    public function getCollectionsDescriptions($visibilities = null)
    {
        
        $collections = array();

        // Get all Opensearch descriptions
        $osDescriptions = $this->getOSDescriptions();
        $where = isset($visibilities) && count($visibilities) > 0 ? ' WHERE visibility IN (' . join(',', $visibilities) . ')' : '';
        $results = $this->dbDriver->query('SELECT name, visibility, owner, model, mapping, licenseid FROM resto.collection ' . $where . ' ORDER BY name');
        while ($rowDescription = pg_fetch_assoc($results)) {
            $collections[$rowDescription['name']] = array_merge(
                FormatUtil::collectionDescription($rowDescription),
                array('osDescription' => $osDescriptions[$rowDescription['name']])
            );
        }

        return $collections;
    }

    /**
     * Check if collection $name exists within resto database
     *
     * @param string $name - collection name
     * @return boolean
     * @throws Exception
     */
    public function collectionExists($name)
    {
        $results = $this->dbDriver->fetch($this->dbDriver->pQuery('SELECT name FROM resto.collection WHERE name=$1', array($name)));
        return !empty($results);
    }

    /**
     * Remove collection from RESTo database
     *
     * @param string $collectionName
     * @return array
     * @throws Exception
     */
    public function removeCollection($collectionName)
    {

        /*
         * Never remove a non empty collection
         */
        if (!$this->collectionIsEmpty($collectionName)) {
            RestoLogUtil::httpError(403, 'Collection ' . $collectionName . ' cannot be deleted - it is not empty !');
        }

        /*
         * Delete (within transaction)
         */
        try {

            $this->dbDriver->query('BEGIN');

            $this->dbDriver->pQuery('DELETE FROM resto.collection WHERE name=$1', array(
                $collectionName
            ));

            $this->dbDriver->pQuery('DELETE FROM resto.right WHERE collection=$1', array(
                $collectionName
            ));
            
            $this->dbDriver->query('COMMIT');

            /*
             * Rollback on error
             */
            if ($this->collectionExists($collectionName)) {
                $this->dbDriver->query('ROLLBACK');
                throw new Exception(500, 'Cannot delete collection ' . $collectionName);
            }

            /*
             * Clear cache
             */
            (new RestoCache())->clear();

        } catch (Exception $e) {
            RestoLogUtil::httpError($e->getCode(), $e->getMessage());
        }

    }

    /**
     * Save collection to database
     *
     * @param RestoCollection $collection
     * @param Array $rights
     *
     * @throws Exception
     */
    public function storeCollection($collection, $rights)
    {
        try {

            /*
             * Start transaction
             */
            $this->dbDriver->query('BEGIN');

            /*
             * Create new entry in collections osdescriptions tables
             */
            $this->storeCollectionDescription($collection);

            /*
             * Store default rights for collection
             *
             * [TODO] Should get userid from  input user ?
             */
            (new RightsFunctions($this->dbDriver))->storeOrUpdateRights(array(
                'right' => $rights,
                'id' => null,
                'groupid' => Resto::GROUP_DEFAULT_ID,
                'collectionName' => $collection->name,
                'featureId' => null
                )
            );
           
            /*
             * Close transaction
             */
            $this->dbDriver->query('COMMIT');

            /*
             * Rollback on errors
             */
            if (! $this->collectionExists($collection->name)) {
                $this->dbDriver->query('ROLLBACK');
                throw new Exception(500, 'Missing collection');
            }

            /*
             * Clear cache
             */
            (new RestoCache())->clear();
            
        } catch (Exception $e) {
            RestoLogUtil::httpError($e->getCode(), $e->getMessage());
        }
    }

    /**
     * Get OpenSearch description array for input collection
     *
     * @param string $collectionName
     * @return array
     * @throws Exception
     */
    private function getOSDescriptions($collectionName = null)
    {
        $osDescriptions = array();

        if (isset($collectionName)) {
            $results = $this->dbDriver->pQuery('SELECT * FROM resto.osdescription WHERE collection=$1', array($collectionName));
        }
        else {
            $results = $this->dbDriver->query('SELECT * FROM resto.osdescription');
        }
        
        while ($description = pg_fetch_assoc($results)) {
            if (!isset($osDescriptions[$description['collection']])) {
                $osDescriptions[$description['collection']]['collection'] = array();
            }
            $osDescriptions[$description['collection']][$description['lang']] = array(
                'ShortName' => $description['shortname'],
                'LongName' => $description['longname'],
                'Description' => $description['description'],
                'Tags' => $description['tags'],
                'Developer' => $description['developer'],
                'Contact' => $description['contact'],
                'Query' => $description['query'],
                'Attribution' => $description['attribution']
            );
        }

        return $osDescriptions;
    }

    /**
     * Store Collection description
     *
     * @param RestoCollection $collection
     *
     */
    private function storeCollectionDescription($collection)
    {

        /*
         * Create collection
         */
        if (! $this->collectionExists($collection->name)) {
            $toBeSet = array(
                'name' => $collection->name,
                'created' => 'now()',
                'model' => $collection->model->getName(),
                'lineage' => '{' . join(',', $collection->model->getLineage()) . '}',
                'licenseid' => $collection->licenseId,
                'mapping' => json_encode($collection->propertiesMapping),
                'visibility' => $collection->visibility,
                'owner' => $collection->owner
            );
            $this->dbDriver->pQuery('INSERT INTO resto.collection (' . join(',', array_keys($toBeSet)) . ') VALUES($1, $2, $3, $4, $5, $6, $7, $8)', array_values($toBeSet));
        }
        /*
         * Otherwise update collection fields (visibility, mapping and licenseid)
         */
        else {
            $this->dbDriver->pQuery('UPDATE resto.collection SET visibility=$1, mapping=$2, licenseid=$3 WHERE name=$4', array(
                $collection->visibility,
                json_encode($collection->propertiesMapping),
                $collection->licenseId,
                $collection->name
            ));
        }

        /*
         * Insert OpenSearch descriptions within osdescriptions table
         * (one description per lang)
         *
         * CREATE TABLE resto.osdescription (
         *  collection          TEXT,
         *  lang                TEXT,
         *  shortname           TEXT,
         *  longname            TEXT,
         *  description         TEXT,
         *  tags                TEXT,
         *  developer           TEXT,
         *  contact             TEXT,
         *  query               TEXT,
         *  attribution         TEXT
         * );
         */
        $this->dbDriver->pQuery('DELETE FROM resto.osdescription WHERE collection=$1', array(
            $collection->name
        ));

        foreach ($collection->osDescription as $lang => $description) {
            $osFields = array(
                'collection',
                'lang'
            );
            $osValues = array(
                '\'' . pg_escape_string($collection->name) . '\'',
                '\'' . pg_escape_string($lang) . '\''
            );

            /*
             * OpenSearch 1.1 draft 5 constraints
             * (http://www.opensearch.org/Specifications/OpenSearch/1.1)
             */
            $validProperties = array(
                'ShortName' => 16,
                //'LongName' => 48,
                'LongName' => -1,
                //'Description' => 1024,
                'Description' => -1,
                'Tags' => 256,
                'Developer' => 64,
                'Contact' => -1,
                'Query' => -1,
                //'Attribution' => 256
                'Attribution' => -1
            );
            foreach (array_keys($description) as $key) {

                /*
                 * Throw exception if property is invalid
                 */
                if (isset($validProperties[$key])) {
                    if ($validProperties[$key] !== -1 && strlen($description[$key]) > $validProperties[$key]) {
                        RestoLogUtil::httpError(400, 'OpenSearch property ' . $key . ' length is greater than ' . $validProperties[$key] . ' characters');
                    }
                    $osFields[] = strtolower($key);
                    $osValues[] = '\'' . pg_escape_string($description[$key]) . '\'';
                }
            }
            $this->dbDriver->query('INSERT INTO resto.osdescription (' . join(',', $osFields) . ') VALUES(' . join(',', $osValues) . ')');
        }

        return true;

    }

    /**
     * Return true if collection is empty, false otherwise
     *
     * @param string $collectionName
     * @return boolean
     */
    private function collectionIsEmpty($collectionName)
    {
        $results = $this->dbDriver->fetch($this->dbDriver->pQuery('SELECT count(id) as count FROM resto.feature WHERE collection=$1 LIMIT 1', array($collectionName)));
        if ($results[0]['count'] === '0') {
            return true;
        }
        return false;
    }

}