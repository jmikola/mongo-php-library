<?php

namespace MongoDB\Tests\GridFS;

use MongoDB\Exception\InvalidArgumentException;
use MongoDB\GridFS\CollectionWrapper;
use MongoDB\GridFS\WritableStream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;

use function str_repeat;

/**
 * Functional tests for the internal WritableStream class.
 */
class WritableStreamFunctionalTest extends FunctionalTestCase
{
    private CollectionWrapper $collectionWrapper;

    public function setUp(): void
    {
        parent::setUp();

        $this->collectionWrapper = new CollectionWrapper($this->manager, $this->getDatabaseName(), 'fs');
    }

    #[DoesNotPerformAssertions]
    public function testValidConstructorOptions(): void
    {
        new WritableStream($this->collectionWrapper, 'filename', [
            '_id' => 'custom-id',
            'chunkSizeBytes' => 2,
            'metadata' => ['foo' => 'bar'],
        ]);
    }

    #[DataProvider('provideInvalidConstructorOptions')]
    public function testConstructorOptionTypeChecks(array $options): void
    {
        $this->expectException(InvalidArgumentException::class);
        new WritableStream($this->collectionWrapper, 'filename', $options);
    }

    public static function provideInvalidConstructorOptions()
    {
        return self::createOptionDataProvider([
            'chunkSizeBytes' => self::getInvalidIntegerValues(true),
            'disableMD5' => self::getInvalidBooleanValues(true),
            'metadata' => self::getInvalidDocumentValues(),
        ]);
    }

    public function testConstructorShouldRequireChunkSizeBytesOptionToBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected "chunkSizeBytes" option to be >= 1, 0 given');
        new WritableStream($this->collectionWrapper, 'filename', ['chunkSizeBytes' => 0]);
    }

    public function testWriteBytesAlwaysUpdatesFileSize(): void
    {
        $stream = new WritableStream($this->collectionWrapper, 'filename', ['chunkSizeBytes' => 1024]);

        $this->assertSame(0, $stream->getSize());
        $this->assertSame(512, $stream->writeBytes(str_repeat('a', 512)));
        $this->assertSame(512, $stream->getSize());
        $this->assertSame(512, $stream->writeBytes(str_repeat('a', 512)));
        $this->assertSame(1024, $stream->getSize());
        $this->assertSame(512, $stream->writeBytes(str_repeat('a', 512)));
        $this->assertSame(1536, $stream->getSize());

        $stream->close();
        $this->assertSame(1536, $stream->getSize());
    }

    #[DataProvider('provideInputDataAndExpectedMD5')]
    public function testWriteBytesCalculatesMD5($input, $expectedMD5): void
    {
        $stream = new WritableStream($this->collectionWrapper, 'filename');
        $stream->writeBytes($input);
        $stream->close();

        $fileDocument = $this->filesCollection->findOne(
            ['_id' => $stream->getFile()->_id],
            ['projection' => ['md5' => 1, '_id' => 0]],
        );

        $this->assertSameDocument(['md5' => $expectedMD5], $fileDocument);
    }

    public static function provideInputDataAndExpectedMD5()
    {
        return [
            ['', 'd41d8cd98f00b204e9800998ecf8427e'],
            ['foobar', '3858f62230ac3c915f300c664312c63f'],
            [str_repeat('foobar', 43520), '88ff0e5fcb0acb27947d736b5d69cb73'],
            [str_repeat('foobar', 43521), '8ff86511c95a06a611842ceb555d8454'],
            [str_repeat('foobar', 87040), '45bfa1a9ec36728ee7338d15c5a30c13'],
            [str_repeat('foobar', 87041), '95e78f624f8e745bcfd2d11691fa601e'],
        ];
    }
}
