<?php

namespace MongoDB\Operation;

use MongoDB\Driver\Command;
use MongoDB\Driver\Server;
use MongoDB\Exception\InvalidArgumentException;
use MongoDB\Exception\InvalidArgumentTypeException;

/**
 * Operation for replacing a document with the findAndModify command.
 *
 * @api
 * @see MongoDB\Collection::findOneAndReplace()
 * @see http://docs.mongodb.org/manual/reference/command/findAndModify/
 */
class FindOneAndReplace implements Executable
{
    const RETURN_DOCUMENT_BEFORE = 1;
    const RETURN_DOCUMENT_AFTER = 2;

    private $findAndModify;

    /**
     * Constructs a findAndModify command for replacing a document.
     *
     * Supported options:
     *
     *  * maxTimeMS (integer): The maximum amount of time to allow the query to
     *    run.
     *
     *  * projection (document): Limits the fields to return for the matching
     *    document.
     *
     *  * returnDocument (enum): Whether to return the document before or after
     *    the update is applied. Must be one either RETURN_DOCUMENT_BEFORE or
     *    RETURN_DOCUMENT_AFTER. The default is RETURN_DOCUMENT_BEFORE.
     *
     *  * sort (document): Determines which document the operation modifies if
     *    the query selects multiple documents.
     *
     *  * upsert (boolean): When true, a new document is created if no document
     *    matches the query. The default is false.
     *
     * @param string       $databaseName   Database name
     * @param string       $collectionName Collection name
     * @param array|object $filter         Query by which to filter documents
     * @param array|object $replacement    Replacement document
     * @param array        $options        Command options
     * @throws InvalidArgumentException
     */
    public function __construct($databaseName, $collectionName, $filter, $replacement, array $options = array())
    {
        if ( ! is_array($filter) && ! is_object($filter)) {
            throw new InvalidArgumentTypeException('$filter', $filter, 'array or object');
        }

        if ( ! is_array($replacement) && ! is_object($replacement)) {
            throw new InvalidArgumentTypeException('$replacement', $replacement, 'array or object');
        }

        if (\MongoDB\is_first_key_operator($replacement)) {
            throw new InvalidArgumentException('First key in $replacement argument is an update operator');
        }

        $options += array(
            'returnDocument' => self::RETURN_DOCUMENT_BEFORE,
            'upsert' => false,
        );

        if (isset($options['maxTimeMS']) && ! is_integer($options['maxTimeMS'])) {
            throw new InvalidArgumentTypeException('"maxTimeMS" option', $options['maxTimeMS'], 'integer');
        }

        if (isset($options['projection']) && ! is_array($options['projection']) && ! is_object($options['projection'])) {
            throw new InvalidArgumentTypeException('"projection" option', $options['projection'], 'array or object');
        }

        if ( ! is_integer($options['returnDocument'])) {
            throw new InvalidArgumentTypeException('"returnDocument" option', $options['returnDocument'], 'integer');
        }

        if ($options['returnDocument'] !== self::RETURN_DOCUMENT_AFTER &&
            $options['returnDocument'] !== self::RETURN_DOCUMENT_BEFORE) {
            throw new InvalidArgumentException('Invalid value for "returnDocument" option: ' . $options['returnDocument']);
        }

        if (isset($options['sort']) && ! is_array($options['sort']) && ! is_object($options['sort'])) {
            throw new InvalidArgumentTypeException('"sort" option', $options['sort'], 'array or object');
        }

        if ( ! is_bool($options['upsert'])) {
            throw new InvalidArgumentTypeException('"upsert" option', $options['upsert'], 'boolean');
        }

        $this->findAndModify = new FindAndModify(
            $databaseName,
            $collectionName,
            array(
                'fields' => isset($options['projection']) ? $options['projection'] : null,
                'maxTimeMS' => isset($options['maxTimeMS']) ? $options['maxTimeMS'] : null,
                'new' => $options['returnDocument'] === self::RETURN_DOCUMENT_AFTER,
                'query' => $filter,
                'sort' => isset($options['sort']) ? $options['sort'] : null,
                'update' => $replacement,
                'upsert' => $options['upsert'],
            )
        );
    }

    /**
     * Execute the operation.
     *
     * @see Executable::execute()
     * @param Server $server
     * @return array|null
     */
    public function execute(Server $server)
    {
        return $this->findAndModify->execute($server);
    }
}
