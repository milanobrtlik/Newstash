<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\Mongo\BrowseNode as MongoBrowseNode;
use App\Service\Mongo\Work;
use App\Service\Mongo\Typeahead;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class SearchController extends AbstractController
{

    /**
     * @Route("/search", name="search_index", methods={"GET"})
     */
    public function search(Request $request)
    {
        // stub for mobile site parity
        return $this->redirect('/', 301);
    }

    /**
     * @Route("/search/books", name="book_search", methods={"GET"})
     * @Template()
     */
    public function titleSearch(Request $request)
    {
        //FIXME
        /*
        $workSearcher   = $this->container->get('bookstash.search.works');
        return $workSearcher->doTitleSearch($request);
        */
    }

    /**
     * @Route("/search/author", name="author_search", methods={"GET"})
     * @Template()
     */
    public function authorSearch(Request $request)
    {
        //FIXME
        /*
        $workSearcher   = $this->container->get('bookstash.search.works');
        return $workSearcher->doAuthorSearch($request);
        */
    }

    /**
     * @Route("/search/json/typeahead", methods={"GET"});
     */
    public function jsonTypeahead(
        Request $request,
        Typeahead $typeahead
    ): JsonResponse
    {
        $text = strtolower($request->get('query'));

        $suggestions = $typeahead->findSuggestions($text);

        foreach ($suggestions as &$s){
            if ('author' == $s['type']){
                $s['url'] = $this->generateUrl('author_search', ['query' => $s['value']]);
            }
            else if ('book' == $s['type']){
                $s['url'] = $this->generateUrl('book_search', [
                    'query' => $s['value'],
                    'work_id' => $s['data']['work_id']
                ]);
            }
        }

        $ret = array(
            'suggestions'   => $suggestions
        );

        return  new JsonResponse($ret);
    }

    /**
     * @Route("/browse/category/{node_id}/{slug}", requirements={"node_id" = "^\d+$"}, name="search_browse_category", methods={"GET"})
     * @Template()
     */
    public function browseCategoryAction(
        Work $workSearcher,
        Request $request,
        $node_id,
        $slug
    ){

        $page           = $request->query->get('page', 1);
        $results        = $workSearcher->byCategory((int)$node_id, (int)$page);

        $results['slug'] = $slug;

        return $results;
    }

    /**
     * @Route("/browse/small/{node_id}", name="browse_node_small")
     * @Template()
     */
    public function topSellingSmall(
        MongoBrowseNode $mbn,
        $node_id,
        $count = 50
    ): array
    {
        $works = $mbn->findTopSellingWorks((int)$node_id, (int)$count);

        return compact('works');
    }

    // ------------------------------------------------------------------------
    // non-routable

    /**
     * @Template()
     */
    public function categoryMenu(
        MongoBrowseNode $mbn,
        $node_ids
    ): array
    {
        // category drop down menu generated from mongo

        if (!is_array($node_ids)){
            $fixed = [];
            foreach (explode(',', (string)$node_ids) as $node_id){
                $fixed[] = (int)$node_id;
            }
            $node_ids = $fixed;
        }

        $nodes = $mbn->findNodesById($node_ids);

        return [
            'nodes' => $nodes
        ];
    }
}
