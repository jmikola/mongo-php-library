<?php

namespace MongoDB\Tests\Collection;

use MongoDB\BulkWriteResult;
use MongoDB\Collection;
use MongoDB\InsertManyResult;
use MongoDB\Driver\Exception\BulkWriteException;
use MongoDB\Driver\Exception\RuntimeException;
use MongoDB\Operation\FindOneAndReplace;
use MongoDB\Tests\CommandObserver;
use MongoDB\Tests\FunctionalTestCase as BaseFunctionalTestCase;
use ArrayIterator;
use IteratorIterator;
use LogicException;
use MultipleIterator;

/**
 * CRUD spec functional tests.
 *
 * @see https://github.com/mongodb/specifications/tree/master/source/crud/tests
 */
class CrudSpecFunctionalTest extends BaseFunctionalTestCase
{
    private $collectionName;
    private $databaseName;
    private $dropCollectionsAfterTestPasses = [];

    public function setUp()
    {
        parent::setUp();

        $this->collectionName = null;
        $this->databaseName = null;
        $this->dropCollectionsAfterTestPasses = [];
    }

    public function tearDown()
    {
        if ( ! $this->hasFailed()) {
            foreach ($this->dropCollectionsAfterTestPasses as $collection) {
                $collection->drop();
            }
        }

        $this->collectionName = null;
        $this->databaseName = null;
        $this->dropCollectionsAfterTestPasses = [];

        parent::tearDown();
    }

    /**
     * @dataProvider provideSpecificationTests
     */
    public function testSpecification(array $initialData, array $test, $minServerVersion, $maxServerVersion, $collectionName, $databaseName)
    {
        /* Apply collection and database names immediately since it is not
         * possible to do so during setUp(). */
        $this->collectionName = $collectionName;
        $this->databaseName = $databaseName;

        $this->setName(str_replace(' ', '_', $test['description']));

        if (isset($minServerVersion) || isset($maxServerVersion)) {
            $this->checkServerVersion($minServerVersion, $maxServerVersion);
        }

        $this->initializeData($initialData);

        $result = null;
        $exception = null;
        $commandStartedEvents = [];

        (new CommandObserver)->observe(
            function() use ($test, &$result, &$exception) {
                try {
                    $result = $this->executeOperation($test['operation']);
                } catch (RuntimeException $e) {
                    $exception = $e;
                }
            },
            function(array $event) use (&$commandStartedEvents) {
                $commandStartedEvents[] = $event['started'];
            }
        );

        if (isset($test['expectations'])) {
            $this->executeExpectations($test['expectations'], $commandStartedEvents);
        }

        $this->executeOutcome($test['operation'], $test['outcome'], $result, $exception);
    }

    public function provideSpecificationTests()
    {
        $testArgs = [];

        foreach (glob(__DIR__ . '/spec-tests/read/aggregate-out.json') as $filename) {
        //foreach (glob('/home/jmikola/workspace/mongodb/specifications/source/crud/tests/write/bulkWrite-arrayFilters.json') as $filename) {
            $json = json_decode(file_get_contents($filename), true);

            $minServerVersion = isset($json['minServerVersion']) ? $json['minServerVersion'] : null;
            $maxServerVersion = isset($json['maxServerVersion']) ? $json['maxServerVersion'] : null;
            $collectionName = isset($json['collectionName']) ? $json['collectionName'] : null;
            $databaseName = isset($json['databaseName']) ? $json['databaseName'] : null;

            foreach ($json['tests'] as $test) {
                $testArgs[] = [$json['data'], $test, $minServerVersion, $maxServerVersion, $collectionName, $databaseName];
            }
        }

        return $testArgs;
    }

    /**
     * Return the test collection name.
     *
     * @see MongoDB\Tests\TestCase::getCollectionName()
     * @return string
     */
    protected function getCollectionName()
    {
        return isset($this->collectionName) ? $this->collectionName : parent::getCollectionName();
    }

    /**
     * Return the test database name.
     *
     * @see MongoDB\Tests\TestCase::getCollectionName()
     * @return string
     */
    protected function getDatabaseName()
    {
        return isset($this->databaseName) ? $this->databaseName : parent::getDatabaseName();
    }

    /**
     * Checks that the server version is within the allowed bounds (if any).
     *
     * @param string|null $minServerVersion
     * @param string|null $maxServerVersion
     * @throws \PHPUnit_Framework_SkippedTestError
     */
    private function checkServerVersion($minServerVersion, $maxServerVersion)
    {
        $serverVersion = $this->getServerVersion();

        if (isset($minServerVersion) && version_compare($serverVersion, $minServerVersion, '<')) {
            $this->markTestSkipped(sprintf('Server version "%s" < minServerVersion "%s"', $serverVersion, $minServerVersion));
        }

        if (isset($maxServerVersion) && version_compare($serverVersion, $maxServerVersion, '>=')) {
            $this->markTestSkipped(sprintf('Server version "%s" >= maxServerVersion "%s"', $serverVersion, $maxServerVersion));
        }
    }

    /**
     * Executes an "expectations" block.
     *
     * This will check that the expected sequence of "command_started_event"
     * documents matches the observed CommandStartedEvents.
     *
     * @param array                 $expectations
     * @param CommandStartedEvent[] $commandStartedEvents
     */
    private function executeExpectations(array $expectations, array $commandStartedEvents)
    {
        $mi = new MultipleIterator(MultipleIterator::MIT_NEED_ANY);
        $mi->attachIterator(new ArrayIterator($expectations));
        $mi->attachIterator(new ArrayIterator($commandStartedEvents));

        foreach ($mi as $events) {
            list($expectedEvent, $commandStartedEvent) = $events;
            $expectedCommandStartedEvent = $expectedEvent['command_started_event'];

            $this->assertSame($expectedCommandStartedEvent['command_name'], $commandStartedEvent->getCommandName());
            $this->assertSame($expectedCommandStartedEvent['database_name'], $commandStartedEvent->getDatabaseName());
            $this->assertSameDocument($expectedCommandStartedEvent['command'], $commandStartedEvent->getCommand());
        }
    }

    /**
     * Executes an "operation" block.
     *
     * @param array $operation
     * @return mixed
     * @throws LogicException if the operation is unsupported
     */
    private function executeOperation(array $operation)
    {
        $collection = new Collection($this->manager, $this->getDatabaseName(), $this->getCollectionName());
        $this->markCollectionForCleanup($collection);

        switch ($operation['name']) {
            case 'aggregate':
                return $collection->aggregate(
                    $operation['arguments']['pipeline'],
                    array_diff_key($operation['arguments'], ['pipeline' => 1])
                );

            case 'bulkWrite':
                return $collection->bulkWrite(
                    array_map([$this, 'prepareBulkWriteRequest'], $operation['arguments']['requests']),
                    isset($operation['arguments']['options']) ? $operation['arguments']['options'] : []
                );

            case 'count':
            case 'countDocuments':
            case 'find':
                return $collection->{$operation['name']}(
                    isset($operation['arguments']['filter']) ? $operation['arguments']['filter'] : [],
                    array_diff_key($operation['arguments'], ['filter' => 1])
                );

            case 'estimatedDocumentCount':
                return $collection->estimatedDocumentCount($operation['arguments']);

            case 'deleteMany':
            case 'deleteOne':
            case 'findOneAndDelete':
                return $collection->{$operation['name']}(
                    $operation['arguments']['filter'],
                    array_diff_key($operation['arguments'], ['filter' => 1])
                );

            case 'distinct':
                return $collection->distinct(
                    $operation['arguments']['fieldName'],
                    isset($operation['arguments']['filter']) ? $operation['arguments']['filter'] : [],
                    array_diff_key($operation['arguments'], ['fieldName' => 1, 'filter' => 1])
                );

            case 'findOneAndReplace':
                $operation['arguments'] = $this->prepareFindAndModifyArguments($operation['arguments']);
                // Fall through

            case 'replaceOne':
                return $collection->{$operation['name']}(
                    $operation['arguments']['filter'],
                    $operation['arguments']['replacement'],
                    array_diff_key($operation['arguments'], ['filter' => 1, 'replacement' => 1])
                );

            case 'findOneAndUpdate':
                $operation['arguments'] = $this->prepareFindAndModifyArguments($operation['arguments']);
                // Fall through

            case 'updateMany':
            case 'updateOne':
                return $collection->{$operation['name']}(
                    $operation['arguments']['filter'],
                    $operation['arguments']['update'],
                    array_diff_key($operation['arguments'], ['filter' => 1, 'update' => 1])
                );

            case 'insertMany':
                return $collection->insertMany(
                    $operation['arguments']['documents'],
                    isset($operation['arguments']['options']) ? $operation['arguments']['options'] : []
                );

            case 'insertOne':
                return $collection->insertOne(
                    $operation['arguments']['document'],
                    array_diff_key($operation['arguments'], ['document' => 1])
                );

            default:
                throw new LogicException('Unsupported operation: ' . $operation['name']);
        }
    }

    /**
     * Executes an "outcome" block.
     *
     * @param array            $operation
     * @param array            $outcome
     * @param mixed            $result
     * @param RuntimeException $exception
     * @return mixed
     * @throws LogicException if the operation is unsupported
     */
    private function executeOutcome(array $operation, array $outcome, $result, RuntimeException $exception = null)
    {
        $expectedError = array_key_exists('error', $outcome) ? $outcome['error'] : false;

        if ($expectedError) {
            $this->assertNull($result);
            $this->assertNotNull($exception);

            $result = $this->extractResultFromException($operation, $outcome, $exception);
        }

        if (array_key_exists('result', $outcome)) {
            $this->assertOutcomeResult($operation, $outcome['result'], $result);
        }

        if (isset($outcome['collection'])) {
            $this->assertOutcomeCollection($outcome['collection']);
        }
    }

    /**
     * Extracts a result from an exception.
     *
     * Errors for bulkWrite and insertMany operations may still report a write
     * result. This method will attempt to extract such a result so that it can
     * be used in executeAssertResult().
     *
     * If no result can be extracted, null will be returned.
     *
     * @param array            $operation
     * @param RuntimeException $exception
     * @return mixed
     */
    private function extractResultFromException(array $operation, array $outcome, RuntimeException $exception)
    {
        switch ($operation['name']) {
            case 'bulkWrite':
                $insertedIds = isset($outcome['result']['insertedIds']) ? $outcome['result']['insertedIds'] : [];

                if ($exception instanceof BulkWriteException) {
                    return new BulkWriteResult($exception->getWriteResult(), $insertedIds);
                }
                break;

            case 'insertMany':
                $insertedIds = isset($outcome['result']['insertedIds']) ? $outcome['result']['insertedIds'] : [];

                if ($exception instanceof BulkWriteException) {
                    return new InsertManyResult($exception->getWriteResult(), $insertedIds);
                }
                break;
        }

        return null;
    }

    /**
     * Asserts the "collection" section of an "outcome" block.
     *
     * @param array $outcomeCollection
     */
    private function assertOutcomeCollection(array $outcomeCollection)
    {
        $collectionName = isset($outcomeCollection['name']) ? $outcomeCollection['name'] : $this->getCollectionName();
        $collection = new Collection($this->manager, $this->getDatabaseName(), $collectionName);

        $mi = new MultipleIterator;
        $mi->attachIterator(new ArrayIterator($outcomeCollection['data']));
        $mi->attachIterator(new IteratorIterator($collection->find()));

        foreach ($mi as $documents) {
            list($expectedDocument, $actualDocument) = $documents;
            $this->assertSameDocument($expectedDocument, $actualDocument);
        }

        $this->markCollectionForCleanup($collection);
    }

    /**
     * Asserts the "result" section of an "outcome" block.
     *
     * @param array $operation
     * @param mixed $expectedResult
     * @param mixed $actualResult
     * @throws LogicException if the operation is unsupported
     */
    private function assertOutcomeResult(array $operation, $expectedResult, $actualResult)
    {
        switch ($operation['name']) {
            case 'aggregate':
                /* Returning a cursor for the $out collection is optional per
                 * the CRUD specification and is not implemented in the library
                 * since we have no concept of lazy cursors. We will not assert
                 * the result here; however, assertOutcomeCollection() will
                 * assert the output collection's contents later.
                 */
                if ( ! \MongoDB\is_last_pipeline_operator_out($operation['arguments']['pipeline'])) {
                    $this->assertSameDocuments($expectedResult, $actualResult);
                }
                break;

            case 'bulkWrite':
                $this->assertInternalType('array', $expectedResult);
                $this->assertInstanceOf('MongoDB\BulkWriteResult', $actualResult);

                if (isset($expectedResult['deletedCount'])) {
                    $this->assertSame($expectedResult['deletedCount'], $actualResult->getDeletedCount());
                }

                if (isset($expectedResult['insertedCount'])) {
                    $this->assertSame($expectedResult['insertedCount'], $actualResult->getInsertedCount());
                }

                if (isset($expectedResult['insertedIds'])) {
                    $this->assertSameDocument(
                        ['insertedIds' => $expectedResult['insertedIds']],
                        ['insertedIds' => $actualResult->getInsertedIds()]
                    );
                }

                if (isset($expectedResult['matchedCount'])) {
                    $this->assertSame($expectedResult['matchedCount'], $actualResult->getMatchedCount());
                }

                if (isset($expectedResult['modifiedCount'])) {
                    $this->assertSame($expectedResult['modifiedCount'], $actualResult->getModifiedCount());
                }

                if (isset($expectedResult['upsertedCount'])) {
                    $this->assertSame($expectedResult['upsertedCount'], $actualResult->getUpsertedCount());
                }

                if (isset($expectedResult['upsertedIds'])) {
                    $this->assertSameDocument(
                        ['upsertedIds' => $expectedResult['upsertedIds']],
                        ['upsertedIds' => $actualResult->getUpsertedIds()]
                    );
                }
                break;

            case 'count':
            case 'countDocuments':
            case 'estimatedDocumentCount':
                $this->assertSame($expectedResult, $actualResult);
                break;

            case 'distinct':
                $this->assertSameDocument(
                    ['values' => $expectedResult],
                    ['values' => $actualResult]
                );
                break;

            case 'find':
                $this->assertSameDocuments($expectedResult, $actualResult);
                break;

            case 'deleteMany':
            case 'deleteOne':
                $this->assertInternalType('array', $expectedResult);
                $this->assertInstanceOf('MongoDB\DeleteResult', $actualResult);

                if (isset($expectedResult['deletedCount'])) {
                    $this->assertSame($expectedResult['deletedCount'], $actualResult->getDeletedCount());
                }
                break;

            case 'findOneAndDelete':
            case 'findOneAndReplace':
            case 'findOneAndUpdate':
                $this->assertSameDocument(
                    ['result' => $expectedResult],
                    ['result' => $actualResult]
                );
                break;

            case 'insertMany':
                $this->assertInternalType('array', $expectedResult);
                $this->assertInstanceOf('MongoDB\InsertManyResult', $actualResult);

                if (isset($expectedResult['insertedCount'])) {
                    $this->assertSame($expectedResult['insertedCount'], $actualResult->getInsertedCount());
                }

                if (isset($expectedResult['insertedIds'])) {
                    $this->assertSameDocument(
                        ['insertedIds' => $expectedResult['insertedIds']],
                        ['insertedIds' => $actualResult->getInsertedIds()]
                    );
                }
                break;

            case 'insertOne':
                $this->assertInternalType('array', $expectedResult);
                $this->assertInstanceOf('MongoDB\InsertOneResult', $actualResult);

                if (isset($expectedResult['insertedCount'])) {
                    $this->assertSame($expectedResult['insertedCount'], $actualResult->getInsertedCount());
                }

                if (isset($expectedResult['insertedId'])) {
                    $this->assertSameDocument(
                        ['insertedId' => $expectedResult['insertedId']],
                        ['insertedId' => $actualResult->getInsertedId()]
                    );
                }
                break;

            case 'replaceOne':
            case 'updateMany':
            case 'updateOne':
                $this->assertInternalType('array', $expectedResult);
                $this->assertInstanceOf('MongoDB\UpdateResult', $actualResult);

                if (isset($expectedResult['matchedCount'])) {
                    $this->assertSame($expectedResult['matchedCount'], $actualResult->getMatchedCount());
                }

                if (isset($expectedResult['modifiedCount'])) {
                    $this->assertSame($expectedResult['modifiedCount'], $actualResult->getModifiedCount());
                }

                if (isset($expectedResult['upsertedCount'])) {
                    $this->assertSame($expectedResult['upsertedCount'], $actualResult->getUpsertedCount());
                }

                if (array_key_exists('upsertedId', $expectedResult)) {
                    $this->assertSameDocument(
                        ['upsertedId' => $expectedResult['upsertedId']],
                        ['upsertedId' => $actualResult->getUpsertedId()]
                    );
                }
                break;

            default:
                throw new LogicException('Unsupported operation: ' . $operation['name']);
        }
    }

    /**
     * Initializes data in the test collections.
     *
     * @param array $initialData
     */
    private function initializeData(array $initialData)
    {
        $collection = new Collection($this->manager, $this->getDatabaseName(), $this->getCollectionName());
        $collection->drop();

        if (empty($initialData)) {
            return;
        }

        $collection->insertMany($initialData);
        $this->markCollectionForCleanup($collection);
    }

    /**
     * Prepares a request element for a bulkWrite operation.
     *
     * @param array $request
     * @return array
     */
    private function prepareBulkWriteRequest(array $request)
    {
        switch ($request['name']) {
            case 'deleteMany':
            case 'deleteOne':
                return [ $request['name'] => [
                    $request['arguments']['filter'],
                    array_diff_key($request['arguments'], ['filter' => 1]),
                ]];

            case 'insertOne':
                return [ 'insertOne' => [ $request['arguments']['document'] ]];

            case 'replaceOne':
                return [ 'replaceOne' => [
                    $request['arguments']['filter'],
                    $request['arguments']['replacement'],
                    array_diff_key($request['arguments'], ['filter' => 1, 'replacement' => 1]),
                ]];

            case 'updateMany':
            case 'updateOne':
                return [ $request['name'] => [
                    $request['arguments']['filter'],
                    $request['arguments']['update'],
                    array_diff_key($request['arguments'], ['filter' => 1, 'update' => 1]),
                ]];

            default:
                throw new LogicException('Unsupported bulk write request: ' . $request['name']);
        }
    }

    /**
     * Mark a collection to be dropped if the test passes.
     *
     * @param Collection $collection
     */
    private function markCollectionForCleanup(Collection $collection)
    {
        $this->dropCollectionsAfterTestPasses[$collection->getNamespace()] = $collection;
    }

    /**
     * Prepares arguments for findOneAndReplace and findOneAndUpdate operations.
     *
     * @param array $arguments
     * @return array
     */
    private function prepareFindAndModifyArguments(array $arguments)
    {
        if (isset($arguments['returnDocument'])) {
            $arguments['returnDocument'] = ('after' === strtolower($arguments['returnDocument']))
                ? FindOneAndReplace::RETURN_DOCUMENT_AFTER
                : FindOneAndReplace::RETURN_DOCUMENT_BEFORE;
        }

        return $arguments;
    }
}
