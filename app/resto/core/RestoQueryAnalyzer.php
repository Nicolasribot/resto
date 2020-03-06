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
 * Simple query analyzer
 */
class RestoQueryAnalyzer
{

    /*
     * RestoContext
     */
    private $context;

    /*
     * RestoUser
     */
    private $user;

    /*
     * Reference to Gazetteer add-on
     */
    private $gazetteer;

    /**
     * Constructor
     *
     * @param RestoContext $context
     * @param RestoUser $user
     */
    public function __construct($context, $user)
    {
        $this->context = $context;
        $this->user = $user;
        if (isset($this->context->addons['Gazetteer'])) {
            $this->gazetteer = new Gazetteer($this->context, $this->user);
        }
    }

    /**
     * Query analyzer process searchTerms and modify query parameters accordingly
     *
     * @param array $params
     * @return array
     */
    public function analyze($params)
    {
        
        /*
         * Store original params
         */
        $inputFilters = $params;
        
        /*
         * [STAC][WFS] datetime is converted into start/end
         */
        if (isset($params['resto:datetime'])) {
            $this->splitDatetime($params['resto:datetime'], $params);
            unset($params['resto:datetime']);
        }
          
        /*
         * Details analysis
         */
        $details = array(
            'language' => $this->context->lang,
            'What' => array(),
            'When' => array(),
            'Where' => array(),
            'Errors' => array(),
            'Explained' => array()
        );

        $hashTodiscard = null;

        /*
         * Query Analyzer on searchTerms
         */
        if (isset($params['searchTerms']) && isset($this->context->addons['NLP'])) {
            $nlp = new NLP($this->context, $this->user);
            $details = $nlp->process(array(
                'q' => $params['searchTerms']
            ));
        } else {
            
            /*
             * Extract hashtags (i.e. #something or -#something)
             */
            $hashtags = isset($params['searchTerms']) ? RestoUtil::extractHashtags($params['searchTerms']) : array();
            
            if (count($hashtags) > 0) {
                $details['What'] = array(
                    'searchTerms' => $hashtags
                );
            }

            /*
             * Extract toponym
             */
            if (isset($this->gazetteer)) {
                $this->extractToponym($params, $details, $hashTodiscard);
            }

        }
        
        /*
         * Not understood - return error
         */
        if (isset($params['searchTerms']) && empty($details['What']) && empty($details['When']) && empty($details['Where'])) {
            $details['appliedFilters'] = $params;
            return array(
                'inputFilters' => $inputFilters,
                'notUnderstood' => true,
                'details' => $details
            );
        }

        /*
         * Where, When, What
         */
        $details['appliedFilters'] = $this->setWhereFilters($details['Where'], $this->setWhenFilters($details['When'], $this->setWhatFilters($details['What'], $params)), $hashTodiscard);
        return array(
            'inputFilters' => $inputFilters,
            'details' => $details
        );
    }

    /**
     * Extract toponym from gazetteer
     *
     * @param array $params
     * @param array $details
     * @param array $hashToDiscard
     */
    private function extractToponym($params, &$details, &$hashToDiscard)
    {
        $foundLocation = null;

        /*
         * Order is "name" over "searchTerms"
         */
        $locationName = $params['geo:name'] ?? $params['searchTerms'] ?? null;
       
        /*
         * Search on toponym name
         */
        if ( isset($locationName) && ! isset($params['geo:lon']) && ! isset($params['geo:geometry']) ) {
            
            /*
             * Search on toponym identifier i.e. geo:name starts with geouid
             */
            if ( strpos($locationName, 'geouid' . Resto::TAG_SEPARATOR) === 0 )
            {
                $location = $this->gazetteer->getToponym(array(
                    'id' => substr($locationName, 7)
                ));
                if (isset($location['_source'])) {
                    $foundLocation = $location['_source'];
                    if (isset($foundLocation['hash'])) {
                        $hashToDiscard = $foundLocation['hash'];
                    }
                    if (isset($foundLocation['wkt'])) {
                        $params['geo:geometry'] = $foundLocation['wkt'];
                    } else {
                        $coordinates = explode(',', $foundLocation['coordinates']);
                        $params['geo:geometry'] = 'POINT(' . trim($coordinates[1]) . ' ' . trim($coordinates[0]) . ')';
                    }
                }
            }
            else {

                /*
                 * [IMPORTANT] The search is performed on a modified "searchTerms" with hashtags REMOVED
                 */
                $locations = $this->gazetteer->search(array(
                    'q' => trim(preg_replace("/(#|-#)([^ ]+)/", '', $locationName))
                ));
                if (isset($locations['hits']) && count($locations['hits']['hits']) > 0) {
                    $foundLocation = $locations['hits']['hits'][0]['_source'];
                    if (isset($foundLocation['wkt'])) {
                        $params['geo:geometry'] = $foundLocation['wkt'];
                    } elseif (isset($foundLocation['coordinates'])) {
                        $coordinates = explode(',', $foundLocation['coordinates']);
                        $params['geo:lon'] = floatval(trim($coordinates[1]));
                        $params['geo:lat'] = floatval(trim($coordinates[0]));
                    }
                }
            }
            
        }

        if (isset($foundLocation)) {
            $details['Where'] = array_merge(array($foundLocation), $details['Where']);
            $details['Explained'] = array_merge(array(
                'processor' => 'WhereProcessor::processIn',
                'word' => $foundLocation['name']
            ), $details['Explained']);
        }

    }


    /**
     * Set location filters from query analysis
     *
     * @param array $where
     * @param array $params
     * @param string $hashTodiscard
     */
    private function setWhereFilters($where, $params, $hashTodiscard = null)
    {
        for ($i = count($where); $i--;) {

            /*
             * Geometry
             */
            if (isset($where[$i]['wkt'])) {
                $params['geo:geometry'] = $where[$i]['wkt'];
            }
            /*
             * Only one toponym is supported (the last one)
             */
            elseif (isset($where[$i]['coordinates'])) {
                $coordinates = array_map('trim', explode(',', $where[$i]['coordinates']));
                $params['geo:lon'] = floatval($coordinates[1]);
                $params['geo:lat'] = floatval($coordinates[0]);
            }
            /*
             * Searching for hash/keywords is faster than geometry
             */
            elseif (isset($where[$i]['searchTerms'])) {
                $params['searchTerms'][] = $where[$i]['searchTerms'];
            } elseif (isset($where[$i]['geouid'])) {
                if (!isset($hashTodiscard) || $where[$i]['hash'] !== $hashTodiscard) {
                    $params['searchTerms'][] = 'geouid' . Resto::TAG_SEPARATOR . $where[$i]['geonameid'];
                }
            }
        }
        if (count($params['searchTerms']) > 0) {
            $params['searchTerms'] = join(' ', $params['searchTerms']);
        } else {
            unset($params['searchTerms']);
        }
        return $params;
    }

    /**
     * Set what filters from query analysis
     *
     * @param array $what
     * @param array $params
     */
    private function setWhatFilters($what, $params)
    {
        $params['searchTerms'] = array();
        foreach ($what as $key => $value) {
            if ($key === 'searchTerms') {
                for ($i = count($value); $i--;) {
                    $params['searchTerms'][] = $value[$i];
                }
            } else {
                $params[$key] = $value;
            }
        }
        return $params;
    }

    /**
     * Set when filters from query analysis
     *
     * @param array $when
     * @param array $params
     */
    private function setWhenFilters($when, $params)
    {
        foreach ($when as $key => $value) {

            /*
             * times is an array of time:start/time:end pairs
             * [TODO] : Currently only one pair is supported
             * [UDPATE] : Are you sure ?
             */
            if ($key === 'times') {
                $params = array_merge($params, $this->timesToOpenSearch($value));
            } else {
                $params['searchTerms'][] = $key . Resto::TAG_SEPARATOR . $value;
            }
        }
        return $params;
    }

    /**
     *
     * @param array $times
     */
    private function timesToOpenSearch($times)
    {
        $params = array();
        for ($i = 0, $ii = count($times); $i < $ii; $i++) {
            foreach ($times[$i] as $key => $value) {
                $params[$key] = $value;
            }
        }
        return $params;
    }

    /**
     * Convert datetime to start/end filters
     * 
     * @param string $datetime
     * @param array $params
     */
    private function splitDatetime($datetime, &$params)
    {

        $dates = explode('/', trim($datetime));
        $model = new DefaultModel();

        if ($dates[0] !== '') {
            $filterKey = $model->getFilterName('start');
            $params[$filterKey] = preg_replace('/<.*?>/', '', $dates[0]);
            $model->validateFilter($filterKey, $params[$filterKey]);
        }
        if (isset($dates[1]) && $dates[1] !== '') {
            $filterKey = $model->getFilterName('end');
            $params[$filterKey] = preg_replace('/<.*?>/', '', $dates[1]);
            $model->validateFilter($filterKey, $params[$filterKey]);
        }

    }

}
