<?php
$startTime = microtime(true);


try {
    $rawRoutes = json_decode(base64_decode(getenv('PLATFORM_ROUTES')), true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    die('Couldn\'t get route info: ' . $e->getMessage());
}

$appName=getenv('PLATFORM_APPLICATION_NAME');


$arySearchColumns = array(
    'option_value',
    'post_content',
    'post_excerpt',
    'post_content_filtered',
    'meta_value',
    'domain'
);



//we only want the primary routes. Or do we only want the ones where type == upstream?
$aryRoutesFiltered = array_filter($rawRoutes, function ($route) use ($appName) {
    return (isset($route['upstream']) && $appName === $route['upstream']);
});

/**
 * we now have a list of NEW domains that are connected to our application as keys, with an array of values that include
 * production_url which is our "from" url, as well as a primary attribute to indicate which one is our default domain.
 * Now we need the "primary" domain (aka default_domain). It *should* be the first item in the array but should we rely
 * on that assumption or should we array_filter so we know we're getting the correct one?
 * @todo there should be one, and one only. should we verify and if not true, throw an error?
 */

$defaultDomainInfo = array_filter($aryRoutesFiltered, function ($route) {
    return (isset($route['primary']) && $route['primary']);
});

$defaultReplaceURLFull = array_key_first($defaultDomainInfo);
echo "Our default replacement URL: ", $defaultReplaceURLFull, PHP_EOL;
$defaultSearchURL=$defaultDomainInfo[$defaultReplaceURLFull]['production_url'];
$defaultSearchDomain = parse_url($defaultSearchURL,PHP_URL_HOST);

$defaultReplaceDomain = parse_url($defaultReplaceURLFull,PHP_URL_HOST);

$strTablePrefix = rtrim(`wp config get table_prefix --url=$defaultSearchURL`);

$tables=[$strTablePrefix.'site',$strTablePrefix.'blogs',];

//$regexSearchPttrn='(%s(?!\.%s))';

$jsonSites=`wp site list --fields=blog_id,url --format=json --url=$defaultSearchURL`;

try {
    $sites= json_decode($jsonSites, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    die('Couldn\'t get list of sites from WordPress instance: ' . $e->getMessage());
}


/**
 * Order the sites by domain "length", desc
 * ie some.sub.domain.com comes before sub.domain.com which comes before domain.com
 */
uasort($aryRoutesFiltered, function ($a, $b) {
    $lena = substr_count($a['production_url'],'.');
    $lenb = substr_count($b['production_url'],'.');
    if ($lena === $lenb) {
        return 0;
    }
    return ($lena < $lenb) ? 1 : -11;
});

/**
 * We need the default_domain to be processed LAST otherwise any domains that are subdomains of it wont be allowed to
 * update their tables. This assumes that our default_domain is first in the list which it *should* be.
 * @todo do we need to search for default_domain, remove it from where it is, and then append it?
 */
//$aryRoutesFiltered = array_reverse($aryRoutesFiltered);


foreach ($aryRoutesFiltered as $urlReplace=>$routeData) {
    $domainSearch = parse_url($routeData['production_url'], PHP_URL_HOST);
    $domainReplace = parse_url($urlReplace, PHP_URL_HOST);
    $blogID=array_search($routeData['production_url'],array_column($sites,'url','blog_id'), true);

    $aryPostOptionsTbls = array(
        $strTablePrefix.((1 === $blogID) ? '' : $blogID . '_').'options',
        $strTablePrefix.((1 === $blogID) ? '' : $blogID . '_').'posts',
        $strTablePrefix.((1 === $blogID) ? '' : $blogID . '_').'postmeta',
    );

    $targetTables = array_merge($tables,$aryPostOptionsTbls);
    echo PHP_EOL,"Updating domain $domainSearch to the new domain $domainReplace... ", PHP_EOL;
    //$regexSearch = sprintf($regexSearchPttrn, preg_quote($domainSearch,'/'), preg_quote($defaultReplaceDomain,'/'));
    $searchTables = implode(' ', $targetTables);
    $searchColumns = implode(',',$arySearchColumns);
    $replacePattern = 'wp search-replace \'%s\' %s %s --include-columns=%s --url=%s --verbose';
    //`wp search-replace '$regexSearch' '$domainReplace' --skip-columns=guid --regex --network --url={$routeData['production_url']} --verbose`;
    /*
     * For the primary domain, we want to run it through the whole network, otherwise we end up with a mismatch between
     * wp_blogs and a site's wp_#_options table
     */
    $network = (isset($routeData['primary']) && $routeData['primary']) ? ' --network' : '';
    $command = sprintf($replacePattern, $domainSearch, $domainReplace, $searchTables, $searchColumns, $routeData['production_url']);
    echo "I am going to execute the following: ", PHP_EOL, $command, PHP_EOL;
    exec($command,$output, $result);
    if (1 === $result) {
        echo 'There was an error attempting to perform the update.',PHP_EOL;
    } else {
        echo implode("\n",$output);
    }
    unset($output,$result);
}

$endTime = microtime(true);

echo "Total execution time: ", ($endTime - $startTime), PHP_EOL;

