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
 * Optical satellite model
 */
class OpticalModel extends SatelliteModel
{

    /**
     * Extended search filters for optical collection
     */
    private $extendedSearchFilters = array(

        'eo:cloudCover' => array(
            'key' => 'cloudCover',
            'osKey' => 'cloudCover',
            'operation' => 'interval',
            'title' => 'Cloud cover expressed in percent',
            'pattern' => '^(\[|\]|[0-9])?[0-9]+$|^[0-9]+?(\[|\])$|^(\[|\])[0-9]+,[0-9]+(\[|\])$',
            'quantity' => array(
                'value' => 'cloud',
                'unit' => '%'
            )
        ),

        'eo:snowCover' => array(
            'key' => 'snowCover',
            'osKey' => 'snowCover',
            'operation' => 'interval',
            'title' => 'Snow cover expressed in percent',
            'pattern' => '^(\[|\]|[0-9])?[0-9]+$|^[0-9]+?(\[|\])$|^(\[|\])[0-9]+,[0-9]+(\[|\])$',
            'quantity' => array(
                'value' => 'snow',
                'unit' => '%'
            )
        )
    );

    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();

        $this->addSearchFilters($this->extendedSearchFilters);

        /*
         * [IMPORTANT] The table resto.feature_optical must exist
         * with columns 'id' and at least the columns list below
         */
        $this->tables[] = array(
            'name' => 'feature_optical',
            'columns' => array(
                'snowcover',
                'cloudcover'
            )
        );

    }

}