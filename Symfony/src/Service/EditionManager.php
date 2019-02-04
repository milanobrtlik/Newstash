<?php
declare(strict_types=1);

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Doctrine\DBAL\Exception\DeadlockException;

class EditionManager
{
    private $logger;
    private $em;

    public function __construct(
        LoggerInterface $logger,
        EntityManagerInterface $em
    )
    {
        $this->logger               = $logger;
        $this->em                   = $em;
    }

    public function stubEdition(string $asin): void
    {
        $this->stubEditions([$asin]);
    }

    public function stubEditions(
        array $asins
    ):void
    {

        $dbh = $this->em->getConnection();

        $sql = '
            INSERT IGNORE INTO edition
                (asin, created_at, updated_at)
            VALUES
                (?, ?, ?)';

        $sth = $dbh->prepare($sql);

        foreach ($asins as $asin) {
            $sth->execute([
                $asin,
                date('Y-m-d H:i:s', strtotime('now')),
                date('Y-m-d H:i:s', strtotime('now'))
            ]);
        }
    }

    public function similarUpdate(
        string $asin,
        array $asins): void
    {

        $dbh = $this->em->getConnection();

        // clear out old records
        $sql = '
            DELETE FROM similar_edition
            WHERE edition_asin = ?';

        $sth = $dbh->prepare($sql);
        $sth->execute([$asin]);

        // insert new records
        $sql = '
            INSERT IGNORE INTO similar_edition
                (edition_asin, similar_asin, xrank)
            VALUES
                (?,?,?)';
        $sth = $dbh->prepare($sql);

        $rank = 1;
        foreach ($asins as $a) {

            $fail = 0;
            while (true) {

                try {
                    $sth->execute([$asin, $a, $rank]);
                    break;

                } catch (DeadlockException $e) {

                    if (10 === $fail) {
                        throw $e;
                    }

                    $this->info(
                        "Caught deadlock exception inserting SimilarEdition, retrying ($fail)");
                    $fail++;
                }
            }

            $sth->execute([$asin, $a, $rank]);
            $rank++;
        }
    }
}
