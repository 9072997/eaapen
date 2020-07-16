<?php
namespace eaapen;

use SessionHandlerInterface;
use Google\Cloud\Firestore\CollectionReference;
use Google\Cloud\Firestore\DocumentReference;
use Google\Cloud\Firestore\FieldValue;

class EaapenSessionHandler implements SessionHandlerInterface
{
    private EaapenFirestore $firestore;
    private CollectionReference $sessionsCollection;
    private DocumentReference $sessionRef;
    private int $gcLimit;
    
    // gcLimit is the max number of docs to delete in one pass at garbage
    // collection. If you make this too big you will stall a normal page
    // load with garbage collection.
    public function __construct(EaapenFirestore $firestore, int $gcLimit = 5)
    {
        $this->firestore = $firestore;
        $this->gcLimit = $gcLimit;
    }
    
    // save references to the sessions collection and session document
    public function open($savePath, $sessionName): bool
    {
        if (empty($savePath)) {
            $savePath = 'EAAPEN_sessions';
        }
        
        $this->sessionsCollection = $this
            ->firestore
            ->collection($savePath);
        
        $this->sessionRef = $this
            ->sessionsCollection
            ->document($sessionName);
        
        return true;
    }
    
    public function read($sessionId): string
    {
        $doc = $this
            ->sessionsCollection
            ->document($sessionId)
            ->snapshot();
        
        if ($doc->exists()) {
            return $doc['data'];
        } else {
            return '';
        }
    }
    
    public function write($sessionId, $sessionData): bool
    {
        $this
            ->sessionsCollection
            ->document($sessionId)
            ->set([
                'data' => $sessionData,
                'modified' => FieldValue::serverTimestamp()
            ]);
        
        return true;
    }
    
    public function close(): bool
    {
        return true;
    }
    
    public function destroy($sessionId): bool
    {
        $this
            ->sessionsCollection
            ->document($sessionId)
            ->delete();
    }
    
    public function gc($maxAgeSeconds): int
    {
        $now = new DateTimeImmutable();
        $maxAge = new DateInterval(intval($maxAgeSeconds) . 'S');
        $oldestAcceptable = $now.sub($maxAge);
        
        $oldSessionsQuery = $this
            ->sessionsCollection
            ->where('modified', '<', $oldestAcceptable)
            ->limit($this->gcLimit);
        
        $this
            ->firestore
            ->deleteQueryDocs($oldSessionsQuery, false);
    }
}
