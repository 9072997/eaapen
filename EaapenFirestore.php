<?php
namespace eaapen;

// Instantiate the Firestore Client based on environment variables for auth
use Google\Cloud\Firestore\FirestoreClient;
use Google\Cloud\Firestore\WriteBatch;
use Google\Cloud\Firestore\CollectionReference;
use Google\Cloud\Firestore\Query;

class EaapenFirestore extends FirestoreClient
{
    public CollectionReference $kvStore;
    private ?WriteBatch $writeBatch = null;
    private int $batchCount = 0;
    private bool $batchCommitIsRegistered = false;
    private bool $sessionHandlerActive = false;
    
    public function __construct(
        string $kvStorePath = 'EAAPEN_kvStore'
    ) {
        parent::__construct();
        $this->kvStore = $this->collection($kvStorePath);
    }
    
    // start a PHP session using Firestore as persistent storage
    public function startSession(int $gcLimit = 5): void
    {
        if (!$this->sessionHandlerActive) {
            // Configure PHP to use the the firestore session handler.
            $handler = new EaapenSessionHandler($this, $gcLimit);
            session_set_save_handler($handler, true);
            session_start();
            
            $this->sessionHandlerActive = true;
        }
    }
    
    // return a firestore batch. It assumes each time you call this function
    // you modify a document in that batch. It will automatically commit a
    // batch when it notices 500 documents have been modified.
    public function autoBatch(): WriteBatch
    {
        // make sure the batch is committed when the script ends.
        // this is a no-op if we have already registered the commit.
        $this->registerBatchCommit();
        
        // this represents affected documents including this one
        $this->batchCount++;
        if (!is_null($this->writeBatch) && $this->batchCount <= 500) {
            // we still have room for one more on this batch
            return $this->writeBatch;
        }
        // looks like we need a new batch.
        
        // Do we need to process the old one first?
        if (!is_null($this->writeBatch)) {
            $this->writeBatch->commit();
        }
        
        // create a new batch. The only document on it so far is this one.
        $this->writeBatch = $this->batch();
        $this->batchCount = 1;
        
        return $this->writeBatch;
    }
    
    // finish up any remaining batched operations
    private function registerBatchCommit(): void
    {
        if (!$this->batchCommitIsRegistered) {
            register_shutdown_function(function () {
                if ($this->batchCount > 0) {
                    $this->writeBatch->commit();
                }
            });
            $this->batchCommitIsRegistered = true;
        }
    }
    
    // read a value from a simple key-value store
    public function kvRead(string $key)
    {
        $document = $this
            ->kvStore
            ->document($key)
            ->snapshot();
        if (isset($document['value'])) {
            return $document['value'];
        } else {
            return null;
        }
    }
    
    // write a value to a simple key-value store
    public function kvWrite(string $key, $value, bool $batched = true): void
    {
        $document = $this
            ->kvStore
            ->document($key);
        $data = ['value' => $value];
        if ($batched) {
            $this
                ->autoBatch()
                ->set($document, $data);
        } else {
            $document->set($data);
        }
    }
    
    // delete a key-value pair from the store
    public function kvDelete(string $key, bool $batched = true): void
    {
        $document = $this
            ->kvStore
            ->document($key);
        if ($batched) {
            $this
                ->autoBatch()
                ->delete($document);
        } else {
            $document->delete();
        }
    }
    
    // get a collection's document's keys as an array of strings
    public function getCollectionKeys(CollectionReference $collection): array
    {
        // tun a collection into an array of references
        $docRefIterator = $collection->listDocuments();
        $docRefs = iterator_to_array($docRefIterator);
        
        // call id() on each of the documents
        $docIds = array_map(
            fn($ref) => $ref->id(),
            $docRefs
        );
        
        return $docIds;
    }

    // delete all documents in a collection (shallow)
    public function deleteCollectionDocs(
        CollectionReference $collection,
        bool $batch = true
    ): void {
        foreach ($collection->listDocuments() as $doc) {
            if ($batch) {
                $this
                    ->autoBatch()
                    ->delete($doc);
            } else {
                $doc->delete();
            }
        }
    }

    // delete all documents returned by a query (shallow)
    public function deleteQueryDocs(Query $query, bool $batch = true): int
    {
        $numDeleted = 0;
        foreach ($query->documents() as $doc) {
            $docRef = $doc->reference();
            if ($batch) {
                $this
                    ->autoBatch()
                    ->delete($docRef);
            } else {
                $docRef->delete();
            }
            $numDeleted++;
        }
        return $numDeleted;
    }
}
