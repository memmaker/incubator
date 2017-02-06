<?php

/*
  +------------------------------------------------------------------------+
  | Phalcon Framework                                                      |
  +------------------------------------------------------------------------+
  | Copyright (c) 2011-2016 Phalcon Team (https://www.phalconphp.com)      |
  +------------------------------------------------------------------------+
  | This source file is subject to the New BSD License that is bundled     |
  | with this package in the file LICENSE.txt.                             |
  |                                                                        |
  | If you did not receive a copy of the license and are unable to         |
  | obtain it through the world-wide-web, please send an email             |
  | to license@phalconphp.com so we can send you a copy immediately.       |
  +------------------------------------------------------------------------+
  | Authors: Andres Gutierrez <andres@phalconphp.com>                      |
  |          Eduar Carvajal <eduar@phalconphp.com>                         |
  +------------------------------------------------------------------------+
*/

namespace Phalcon\Session\Adapter;

use DateInterval;
use DateTime;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use MongoDB\DeleteResult;
use Phalcon\Session\Adapter;
use Phalcon\Session\AdapterInterface;
use Phalcon\Session\Exception;

/**
 * Phalcon\Session\Adapter\Mongo
 * Mongo adapter for Phalcon\Session
 */
class Mongo extends Adapter implements AdapterInterface
{
    /**
     * Current session data
     *
     * @var string
     */
    protected $data;

    /**
     * Class constructor.
     *
     * @param  array     $options
     * @throws Exception
     */
    public function __construct($options = null)
    {
        if (!isset($options['collection'])) {
            throw new Exception("The parameter 'collection' is required");
        }

        session_set_save_handler(
            [$this, 'open'],
            [$this, 'close'],
            [$this, 'read'],
            [$this, 'write'],
            [$this, 'destroy'],
            [$this, 'gc']
        );

        parent::__construct($options);
    }

    /**
     * {@inheritdoc}
     *
     * @return boolean
     */
    public function open()
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function close()
    {
        return true;
    }

    /**
     * {@inheritdoc}
     *
     * @param  string $sessionId
     * @return string
     */
    public function read($sessionId)
    {
        $sessionData = $this->getCollection()->findOneAndUpdate(['_id' => $sessionId], ['$set' => ['modified' => new UTCDateTime(time() * 1000)]]);

        if (!isset($sessionData->data)) {
            return '';
        }

        $this->data = $sessionData->data;
        return $sessionData->data;
    }

    /**
     * {@inheritdoc}
     *
     * @param  string $sessionId
     * @param  string $sessionData
     * @return bool
     */
    public function write($sessionId, $sessionData)
    {
        if ($this->data === $sessionData) {
            return true;
        }

        $sessionData = [
            '_id'      => $sessionId,
            'modified' => new UTCDateTime(time() * 1000),
            'data'     => $sessionData
        ];

        $this->getCollection()->updateOne(['_id' => $sessionData['_id']], ['$set' => $sessionData ], ['upsert' => true]);

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function destroy($sessionId = null)
    {
        if (is_null($sessionId)) {
            $sessionId = $this->getId();
        }

        $this->data = null;

        /** @var DeleteResult $remove */
        $remove = $this->getCollection()->deleteOne(['_id' => $sessionId]);

        return $remove->isAcknowledged();
    }

    /**
     * {@inheritdoc}
     * @param string $maxLifetime
     */
    public function gc($maxLifetime)
    {
        $minAge = new DateTime();
        $minAge->sub(new DateInterval('PT' . $maxLifetime . 'S'));
        $minAgeMongo = new UTCDateTime($minAge->getTimestamp() * 1000);

        $query = ['modified' => ['$lte' => $minAgeMongo]];
        /** @var DeleteResult $remove */
        $remove = $this->getCollection()->deleteMany($query);

        return $remove->isAcknowledged();
    }

    /**
     * @return Collection
     */
    protected function getCollection()
    {
        $options = $this->getOptions();

        return $options['collection'];
    }
}
