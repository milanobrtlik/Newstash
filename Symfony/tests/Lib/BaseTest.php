<?php
declare(strict_types=1);

namespace App\Tests\Lib;

use App\Entity\Edition;
use App\Entity\User;
use App\Entity\Lead;
use App\Entity\BrowseNode;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use \SimpleXMLElement;
use DateTime;

abstract class BaseTest extends WebTestCase
{
    protected $DBSetup = false;
    protected $client;

    public function setup()
    {
        $this->client = static::createClient();

        if ($this->DBSetup) {
            $this->resetDB();
        }

        parent::setup();
    }

    /**
     *
     */
    protected function resetDB(): void
    {

        $root = static::$kernel->getContainer()->get('kernel')->getProjectDir();

        $snapshot       = $root . '/var/testing-db-snap.sql';
        $database_url   = getenv('DATABASE_URL');

        preg_match('|mysql://([^:]+):([^@]+)@([^:]+):[\d]+/(.*)|', $database_url, $matches);
        list($str, $user, $pass, $host, $db) = $matches;

        if ($pass){
            $pass = "-p$pass";
        }

        if (    (file_exists($snapshot)) &&
                (time() - filemtime($snapshot) <= 60 * 60) &&
                (filesize($snapshot)) // missing mysql = empty file
            ){ // 1 hour

            exec("mysql -u $user -h $host $pass $db <$snapshot");
        }
        else{
            $root = static::$kernel->getContainer()->get('kernel')->getProjectDir();
            exec($root.'/bin/console --env=test doctrine:schema:drop --force');
            exec($root."/bin/console --env=test doctrine:schema:create");
            exec($root.'/bin/console --env=test doctrine:fixtures:load --no-interaction');

            exec("mysqldump -u $user -h $host $pass $db >$snapshot");
        }

    }

    /**
     *
     */
    protected function doNumJsonQuery(
        string $endpoint,
        string $method,
        int $status,
        ?string $json_body = null,
        array $params = [],
        bool $throw = true
    ): SymfonyResponse {
        $response = $this->doJsonQuery($endpoint, $method, $json_body, $params, $throw);
        $data     = json_decode($response->getContent(), true);

        $this->assertTrue(
            $response->headers->contains(
                'Content-Type',
                'application/json'
            ),
            $endpoint.' did not return Content-Type: application/json header'
        );

        $returned_status = $response->getStatusCode();

        $this->assertEquals(
            $status,
            $returned_status
        );

        return $response;
    }

    /**
     *
     */
    protected function doJsonQuery(
        string $endpoint,
        string $method = 'GET',
        array $data = null,
        array $params = [],
        bool $throw = true
    ): SymfonyResponse {

        $client = $this->client;

        $body = '';
        if ($data) {
            $body = json_encode($data);
        }

        $client->request(
            $method,
            $endpoint,
            $params,
            [],
            [
                'CONTENT_TYPE' => 'application/json',
            ],
            $body
        );

        return $client->getResponse();
    }

    /**
     *
     */
    protected function validateUnsetBasic(array &$incoming): void
    {
        $this->validUnsetTimestamp($incoming);
        $this->validUnsetDate($incoming);
    }

    protected function validUnsetTimestamp(
        array &$incoming
    ): void {

        $this->assertGreaterThan(
            1481832964,  // when I wrote this
            $incoming['timestamp']
        );
        $this->assertLessThan(
            1923596293, // Dec 15, 2030
            $incoming['timestamp']
        );
        unset($incoming['timestamp']);
    }

    /**
     *
     */
    protected function validUnsetDate(
        array &$incoming,
        string $key = 'date',
        bool $format = false
    ): void {

        $this->assertGreaterThan(
            1481832964,  // when I wrote this
            strtotime($incoming[$key])
        );
        $this->assertLessThan(
            1923596293, // Dec 15, 2030
            strtotime($incoming[$key])
        );

        if ($format) {
            switch ($format) {
                case 'ISO8601':
                    // http://stackoverflow.com/questions/28020805/regex-validate-correct-iso8601-date-string-with-time
                    $this->assertRegExp(
                        '/^(?:[1-9]\d{3}-(?:(?:0[1-9]|1[0-2])-(?:0[1-9]|1\d|2[0-8])|(?:0[13-9]|1[0-2])-(?:29|30)|(?:0[13578]|1[02])-31)|(?:[1-9]\d(?:0[48]|[2468][048]|[13579][26])|(?:[2468][048]|[13579][26])00)-02-29)T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:Z|[+-][01]\d:[0-5]\d)$/',
                        $incoming[$key]
                    );

                    break;
                default:
                    throw new \Exception("Not sure how to validate date format \"$format\"");
            }
        }

        unset($incoming[$key]);
    }


    protected function loadEditionFromXML(
        string $file
    ): ?Edition
    {

        $em             = self::$container->get('doctrine')->getManager();

        $root   = self::$container->get('kernel')->getProjectDir();
        $xml    = file_get_contents("$root/tests/Fixture/$file");

        $sxe = new SimpleXMLElement($xml);

        $this->loadBrowseNodesFromSxe($sxe->Items->Item[0]);

        $productParser = self::$container->get('test.App\Service\Apa\ProductParser');

        return $productParser->ingest($sxe->Items->Item[0]);
    }

    /**
     * This itterates the browsenodes in a given product xml and adds
     * them to the testing db to support the ingestion of that product
     **/
    protected function loadBrowseNodesFromSxe(
        SimpleXMLElement $sxe
    ): void
    {

        $em         = self::$container->get('doctrine')->getManager();
        $map        = [];
        $created    = [];

        if (($sxe->BrowseNodes) and ($sxe->BrowseNodes->BrowseNode)){
            foreach ($sxe->BrowseNodes->BrowseNode as $bn){

                $b          = $bn;
                $flip       = [];
                $flip[]     = (string)$b->BrowseNodeId;
                $map[(string)$b->BrowseNodeId] = $b;

                while (($b->Ancestors) and ($a = $b->Ancestors)){
                    $b = $a->BrowseNode;

                    $flip[] = (string)$b->BrowseNodeId;
                    $map[(string)$b->BrowseNodeId] = $b;

                    if ($b->IsCategoryRoot){
                        break;
                    }

                }

                $parent = null;
                foreach (array_reverse($flip) as $id) {
                    $bn         = $map[$id];

                    if (isset($created[$id])) {
                        $browseNode = $created[$id];
                    }
                    else{
                        $browseNode = new BrowseNode();
                        $browseNode
                            ->setId((int)$bn->BrowseNodeId)
                            ->setName((string)$bn->Name);
                        $em->persist($browseNode);
                        $created[$id] = $browseNode;
                    }

                    if ($parent){
                        $browseNode->addParent($parent);
                    }


                    $parent = $browseNode;
                }
            }

            $em->flush();
        }
    }

    public function createUser(): User
    {
        $em = self::$container->get('doctrine')->getManager();

        $user = new User();

        $id = uniqid();

        $user->setUsername('testuser-'. $id)
            ->setEmail($id . '-no@no.no')
            ->setPassword('----locked----');

        $em->persist($user);

        $em->flush();

        return $user;

    }

    protected function validUnsetTimestamps(array &$incoming)
    {
        $this->validUnsetDates($incoming, ['updated_at', 'created_at']);
    }

    protected function validUnsetDates(array &$incoming, array $fields)
    {
        foreach ($fields as $field) {
            $this->assertGreaterThan(
                new DateTime('@1481832965'),
                $incoming[$field]
            );
            $this->assertLessThan(
                new DateTime('@1923596294'),
                $incoming[$field]
            );

            unset($incoming[$field]);
        }
    }

}
