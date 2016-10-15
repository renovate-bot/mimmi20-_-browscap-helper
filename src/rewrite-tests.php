<?php
/*******************************************************************************
 * INIT
 ******************************************************************************/
ini_set('memory_limit', '-1');
ini_set('max_execution_time', '-1');
ini_set('max_input_time', '-1');
ini_set('display_errors', 1);
error_reporting(E_ALL);

date_default_timezone_set('Europe/Berlin');

chdir(dirname(__DIR__));

require 'vendor/autoload.php';

$logger = new \Monolog\Logger('browser-detector-tests');
$logger->pushHandler(new \Monolog\Handler\NullHandler());

$cache    = new \WurflCache\Adapter\NullStorage();
$detector = new \BrowserDetector\BrowserDetector($cache, $logger);

$sourceDirectory = 'vendor/mimmi20/browser-detector/tests/issues/';

$filesArray  = scandir($sourceDirectory, SCANDIR_SORT_ASCENDING);
$files       = [];
$testCounter = [];

foreach ($filesArray as $filename) {
    if (in_array($filename, ['.', '..'])) {
        continue;
    }

    if (!is_dir($sourceDirectory . DIRECTORY_SEPARATOR . $filename)) {
        $files[] = $filename;
        echo 'file ', $filename, ' is out of strcture', PHP_EOL;
        continue;
    }

    $subdirFilesArray = scandir($sourceDirectory . DIRECTORY_SEPARATOR . $filename, SCANDIR_SORT_ASCENDING);

    foreach ($subdirFilesArray as $subdirFilename) {
        if (in_array($subdirFilename, ['.', '..'])) {
            continue;
        }

        $files[] = $filename . DIRECTORY_SEPARATOR . $subdirFilename;
        $group = $filename;

        if ('00000-browscap' === $filename) {
            $group = '00000';
        }

        $testCounter[$group][$filename . DIRECTORY_SEPARATOR . $subdirFilename] = 0;
    }
}

$checks = [];
$data   = [];

foreach ($files as $filename) {
    $file = new \SplFileInfo($sourceDirectory . DIRECTORY_SEPARATOR . $filename);

    echo 'file ', $file->getBasename(), PHP_EOL;
    echo '    checking ...', PHP_EOL;

    /** @var $file \SplFileInfo */
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    echo '    reading ...', PHP_EOL;

    $tests = require_once $file->getPathname();

    if (empty($tests)) {
        echo 'removing empty file ', $file->getBasename(), ' ...', PHP_EOL;
        unlink($file->getPathname());

        continue;
    }

    foreach ($testCounter as $group => $filesinGroup) {
        foreach (array_keys($filesinGroup) as $fileinGroup) {
            if ($fileinGroup !== $filename) {
                continue;
            }

            $testCounter[$group][$fileinGroup] += count($tests);
        }
    }

    echo '    processing ...', PHP_EOL;
    handleFile($tests, $file, $detector, $data, $checks);
}

$circleFile      = 'vendor/mimmi20/browser-detector/circle.yml';
$circleciContent = 'machine:
  php:
    version: 7.0.4
  timezone:
    Europe/Berlin

dependencies:
  override:
    - composer update --optimize-autoloader --prefer-dist --prefer-stable --no-interaction --no-progress

test:
  override:
    - mkdir -p $CIRCLE_TEST_REPORTS/phpunit
    - vendor/bin/phpunit -c phpunit.library.xml --exclude-group useragenttest --coverage-text --colors=auto --log-junit $CIRCLE_TEST_REPORTS/phpunit/junit.xml
    #- vendor/bin/phpunit -c phpunit.compare.xml --no-coverage --group useragenttest --colors=auto
';

$circleLines = [];

foreach ($testCounter as $group => $filesinGroup) {
    $count = 0;

    foreach (array_keys($filesinGroup) as $fileinGroup) {
        $count += $testCounter[$group][$fileinGroup];
    }

    $circleLines[$group] = $count;
}

$countArray = [];
$groupArray = [];

foreach ($circleLines as $group => $count) {
    $countArray[$group] = $count;
    $groupArray[$group] = $group;
}

array_multisort(
    $countArray, SORT_NUMERIC, SORT_ASC,
    $groupArray, SORT_NUMERIC, SORT_ASC,
    $circleLines
);
/**/

foreach ($circleLines as $group => $count) {
    $circleciContent .= '    #' . str_pad($count, 6, ' ', STR_PAD_LEFT) . ' tests' . PHP_EOL;
    $circleciContent .= '    - vendor/bin/phpunit -c phpunit.compare.xml --no-coverage --group ' . $group . ' --colors=auto --test-suffix=' . $group . 'Test.php' . PHP_EOL;
}

echo 'writing ', $circleFile, ' ...', PHP_EOL;
file_put_contents($circleFile, $circleciContent);
echo 'done', PHP_EOL;

function handleFile(
    array $tests,
    \SplFileInfo $file,
    \BrowserDetector\BrowserDetector $detector,
    array &$data,
    array &$checks
) {
    $outputDetector = "<?php\n\nreturn [\n";

    foreach ($tests as $key => $test) {
        if (isset($data[$key])) {
            // Test data is duplicated for key
            echo '    Test data is duplicated for key "' . $key . '"', PHP_EOL;
            unset($tests[$key]);
            continue;
        }

        if (isset($checks[$test['ua']])) {
            // UA was added more than once
            echo '    UA "' . $test['ua'] . '" added more than once, now for key "' . $key . '", before for key "'
                . $checks[$test['ua']] . '"', PHP_EOL;
            unset($tests[$key]);
            continue;
        }

        $data[$key]          = $test;
        $checks[$test['ua']] = $key;

        echo '    processing Test ', $key, ' ...', PHP_EOL;
        $outputDetector .= handleTest($test, $detector, $key);
    }

    $basename = $file->getBasename();

    if (empty($tests)) {
        echo 'removing empty file ', $basename, ' ...', PHP_EOL;
        unlink($file->getPathname());

        return;
    }

    $outputDetector .= "];\n";

    echo 'writing file ', $basename, ' ...', PHP_EOL;

    file_put_contents($file->getPath() . '/' . $basename, $outputDetector);
}

function handleTest(array $test, \BrowserDetector\BrowserDetector $detector, $key)
{
    /** rewrite platforms */

    list($platformCodename, $platformMarketingname, $platformVersion, $platformBits, $platformMaker, $platformBrandname, $platform) = rewritePlatforms(
        $test,
        $detector,
        $key
    );
    /** @var $platform \UaResult\Os\OsInterface */

    /** rewrite devices */

    list($deviceBrand, $deviceCode, $devicePointing, $deviceType, $deviceMaker, $deviceName, $deviceOrientation, $device) = rewriteDevice(
        $test,
        $detector,
        $platform
    );
    /** @var $device \BrowserDetector\Matcher\Device\DeviceHasSpecificPlatformInterface */

    if (null !== ($platform = $device->detectOs())) {
        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    }

    /** rewrite test numbers */

    if (preg_match('/^test\-(\d+)\-(\d+)$/', $key, $matches)) {
        $key = 'test-' . sprintf('%1$05d', (int) $matches[1]) . '-' . sprintf('%1$05d', (int) $matches[2]);
    } elseif (preg_match('/^test\-(\d+)$/', $key, $matches)) {
        $key = 'test-' . sprintf('%1$05d', (int) $matches[1]) . '-00000';
    } elseif (preg_match('/^test\-(\d+)\-test(\d+)$/', $key, $matches)) {
        $key = 'test-' . sprintf('%1$05d', (int) $matches[1]) . '-' . sprintf('%1$05d', (int) $matches[2]);
    }

    return "    '$key' => [
        'ua'         => '" . str_replace(['\\', "'"], ['\\\\', "\\'"], $test['ua']) . "',
        'properties' => [
            'Browser_Name'            => '" . str_replace(
        ['\\', "'"],
        ['\\\\', "\\'"],
        $test['properties']['Browser_Name']
    ) . "',
            'Browser_Type'            => '" . str_replace(
        ['\\', "'"],
        ['\\\\', "\\'"],
        $test['properties']['Browser_Type']
    ) . "',
            'Browser_Bits'            => " . str_replace(
        ['\\', "'"],
        ['\\\\', "\\'"],
        $test['properties']['Browser_Bits']
    ) . ",
            'Browser_Maker'           => '" . str_replace(
        ['\\', "'"],
        ['\\\\', "\\'"],
        $test['properties']['Browser_Maker']
    ) . "',
            'Browser_Modus'           => '" . str_replace(
        ['\\', "'"],
        ['\\\\', "\\'"],
        $test['properties']['Browser_Modus']
    ) . "',
            'Browser_Version'         => '" . str_replace(
        ['\\', "'"],
        ['\\\\', "\\'"],
        $test['properties']['Browser_Version']
    ) . "',
            'Platform_Codename'       => '" . str_replace(['\\', "'"], ['\\\\', "\\'"], $platformCodename) . "',
            'Platform_Marketingname'  => '" . str_replace(['\\', "'"], ['\\\\', "\\'"], $platformMarketingname) . "',
            'Platform_Version'        => '" . str_replace(['\\', "'"], ['\\\\', "\\'"], $platformVersion) . "',
            'Platform_Bits'           => " . str_replace(['\\', "'"], ['\\\\', "\\'"], $platformBits) . ",
            'Platform_Maker'          => '" . str_replace(['\\', "'"], ['\\\\', "\\'"], $platformMaker) . "',
            'Platform_Brand_Name'     => '" . str_replace(['\\', "'"], ['\\\\', "\\'"], $platformBrandname) . "',
            'Device_Name'             => '" . str_replace(['\\', "'"], ['\\\\', "\\'"], $deviceName) . "',
            'Device_Maker'            => '" . str_replace(['\\', "'"], ['\\\\', "\\'"], $deviceMaker) . "',
            'Device_Type'             => " . ($deviceType === null ? 'null' : "'" . str_replace(
    ['\\', "'"],
    ['\\\\', "\\'"],
    $deviceType
) . "'") . ",
            'Device_Pointing_Method'  => " . ($devicePointing === null ? 'null' : "'" . str_replace(
    ['\\', "'"],
    ['\\\\', "\\'"],
    $devicePointing
) . "'") . ",
            'Device_Dual_Orientation' => " . ($deviceOrientation === null ? 'null' : ($deviceOrientation ? 'true' : 'false')) . ",
            'Device_Code_Name'        => '" . str_replace(['\\', "'"], ['\\\\', "\\'"], $deviceCode) . "',
            'Device_Brand_Name'       => '" . str_replace(['\\', "'"], ['\\\\', "\\'"], $deviceBrand) . "',
            'RenderingEngine_Name'    => '" . str_replace(
        ['\\', "'"],
        ['\\\\', "\\'"],
        $test['properties']['RenderingEngine_Name']
    ) . "',
            'RenderingEngine_Version' => '" . str_replace(
        ['\\', "'"],
        ['\\\\', "\\'"],
        $test['properties']['RenderingEngine_Version']
    ) . "',
            'RenderingEngine_Maker'   => '" . str_replace(
        ['\\', "'"],
        ['\\\\', "\\'"],
        $test['properties']['RenderingEngine_Maker']
    ) . "',
        ],
    ],\n";
}

function rewritePlatforms(array $test, \BrowserDetector\BrowserDetector $detector, $key)
{
    if (isset($test['properties']['Platform_Codename'])) {
        $platformCodename = $test['properties']['Platform_Codename'];
    } elseif (isset($test['properties']['Platform_Name'])) {
        $platformCodename = $test['properties']['Platform_Name'];
    } else {
        echo '["' . $key . '"] platform name for UA "' . $test['ua'] . '" is missing, using "unknown" instead', PHP_EOL;

        $platformCodename = 'unknown';
    }

    if (isset($test['properties']['Platform_Marketingname'])) {
        $platformMarketingname = $test['properties']['Platform_Marketingname'];
    } else {
        $platformMarketingname = $platformCodename;
    }

    if (isset($test['properties']['Platform_Version'])) {
        $platformVersion = $test['properties']['Platform_Version'];
    } else {
        echo '["' . $key . '"] platform version for UA "' . $test['ua'] . '" is missing, using "unknown" instead', PHP_EOL;

        $platformVersion = 'unknown';
    }

    if (isset($test['properties']['Platform_Bits'])) {
        $platformBits = $test['properties']['Platform_Bits'];
    } else {
        echo '["' . $key . '"] platform bits for UA "' . $test['ua'] . '" are missing, using "unknown" instead', PHP_EOL;

        $platformBits = 'unknown';
    }

    if (isset($test['properties']['Platform_Maker'])) {
        $platformMaker = $test['properties']['Platform_Maker'];
    } else {
        echo '["' . $key . '"] platform maker for UA "' . $test['ua'] . '" is missing, using "unknown" instead', PHP_EOL;

        $platformMaker = 'unknown';
    }

    if (isset($test['properties']['Platform_Brand_Name'])) {
        $platformBrandname = $test['properties']['Platform_Brand_Name'];
    } else {
        echo '["' . $key . '"] platform brand for UA "' . $test['ua'] . '" is missing, using "unknown" instead', PHP_EOL;

        $platformBrandname = 'unknown';
    }

    $useragent = $test['ua'];

    // rewrite Darwin platform
    if ('Darwin' === $platformCodename) {
        $platform = \BrowserDetector\Detector\Factory\Platform\DarwinFactory::detect($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif ('Windows' === $platformCodename) {
        $platform = \BrowserDetector\Detector\Factory\Platform\WindowsFactory::detect($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif ('Android' === $platformCodename && preg_match('/windows phone/i', $useragent)) {
        $platform = new \BrowserDetector\Detector\Os\WindowsPhoneOs($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (preg_match('/Puffin\/[\d\.]+I(T|P)/', $useragent)) {
        $platform = new \BrowserDetector\Detector\Os\Ios($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (preg_match('/Puffin\/[\d\.]+A(T|P)/', $useragent)) {
        $platform = new \BrowserDetector\Detector\Os\AndroidOs($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (preg_match('/Puffin\/[\d\.]+W(T|P)/', $useragent)) {
        $platform = new \BrowserDetector\Detector\Os\WindowsPhoneOs($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== stripos($useragent, 'linux mint')) {
        $platform = new \BrowserDetector\Detector\Os\Mint($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== stripos($useragent, 'kubuntu')) {
        $platform = new \BrowserDetector\Detector\Os\Kubuntu($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== stripos($useragent, 'ubuntu')) {
        $platform = new \BrowserDetector\Detector\Os\Ubuntu($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (preg_match('/HP\-UX/', $useragent)) {
        $platform = new \BrowserDetector\Detector\Os\Hpux($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif ('Windows' === $platformCodename && preg_match('/windows ce/i', $useragent)) {
        $platform = new \BrowserDetector\Detector\Os\WindowsCe($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (preg_match('/(red hat|redhat)/i', $useragent)) {
        $platform = new \BrowserDetector\Detector\Os\Redhat($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif ('Windows Mobile OS' === $platformCodename && preg_match('/Windows Mobile; WCE/', $useragent)) {
        $platform = new \BrowserDetector\Detector\Os\WindowsCe($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== strpos($useragent, 'Windows Phone')) {
        $platform = new \BrowserDetector\Detector\Os\WindowsPhoneOs($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== strpos($useragent, 'wds')) {
        $platform = new \BrowserDetector\Detector\Os\WindowsPhoneOs($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== stripos($useragent, 'wpdesktop')) {
        $platform = new \BrowserDetector\Detector\Os\WindowsPhoneOs($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== stripos($useragent, 'xblwp7')) {
        $platform = new \BrowserDetector\Detector\Os\WindowsPhoneOs($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== stripos($useragent, 'zunewp7')) {
        $platform = new \BrowserDetector\Detector\Os\WindowsPhoneOs($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== strpos($useragent, 'Tizen')) {
        $platform = new \BrowserDetector\Detector\Os\Tizen($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== strpos($useragent, 'Windows CE')) {
        $platform = new \BrowserDetector\Detector\Os\WindowsCe($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (preg_match('/MIUI/', $useragent)) {
        $platform = new \BrowserDetector\Detector\Os\MiuiOs($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== strpos($useragent, 'Linux; Android')) {
        $platform = new \BrowserDetector\Detector\Os\AndroidOs($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== strpos($useragent, 'Linux; U; Android')) {
        $platform = new \BrowserDetector\Detector\Os\AndroidOs($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== strpos($useragent, 'U; Adr')) {
        $platform = new \BrowserDetector\Detector\Os\AndroidOs($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== strpos($useragent, 'Android') || false !== strpos($useragent, 'MTK')) {
        $platform = new \BrowserDetector\Detector\Os\AndroidOs($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== strpos($useragent, 'UCWEB/2.0 (Linux; U; Opera Mini')) {
        $platform = new \BrowserDetector\Detector\Os\AndroidOs($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== strpos($useragent, 'Linux; GoogleTV')) {
        $platform = new \BrowserDetector\Detector\Os\AndroidOs($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== strpos($useragent, 'OpenBSD')) {
        $platform = new \BrowserDetector\Detector\Os\OpenBsd($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== strpos($useragent, 'Symbian') || false !== strpos($useragent, 'Series 60')) {
        $platform = new \BrowserDetector\Detector\Os\Symbianos($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== strpos($useragent, 'MIDP')) {
        $platform = new \BrowserDetector\Detector\Os\Java($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== strpos($useragent, 'Windows NT 10.0')) {
        $platform = new \BrowserDetector\Detector\Os\Windows10($useragent, '10.0');

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== strpos($useragent, 'Windows NT 6.4')) {
        $platform = new \BrowserDetector\Detector\Os\Windows10($useragent, '6.4');

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== strpos($useragent, 'Windows NT 6.3') && false !== strpos($useragent, 'ARM')) {
        $platform = new \BrowserDetector\Detector\Os\WindowsRt($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== strpos($useragent, 'Windows NT 6.3')) {
        $platform = new \BrowserDetector\Detector\Os\Windows81($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== strpos($useragent, 'Windows NT 6.2') && false !== strpos($useragent, 'ARM')) {
        $platform = new \BrowserDetector\Detector\Os\WindowsRt($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== strpos($useragent, 'Windows NT 6.2')) {
        $platform = new \BrowserDetector\Detector\Os\Windows8($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== strpos($useragent, 'Windows NT 6.1')) {
        $platform = new \BrowserDetector\Detector\Os\Windows7($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== strpos($useragent, 'Windows NT 6')) {
        $platform = new \BrowserDetector\Detector\Os\WindowsVista($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== strpos($useragent, 'Windows NT 5.3')) {
        $platform = new \BrowserDetector\Detector\Os\WindowsXp($useragent, '5.3');

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== strpos($useragent, 'Windows NT 5.2')) {
        $platform = new \BrowserDetector\Detector\Os\WindowsXp($useragent, '5.2');

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== strpos($useragent, 'Windows NT 5.1')) {
        $platform = new \BrowserDetector\Detector\Os\WindowsXp($useragent, '5.1');

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== strpos($useragent, 'Windows NT 5.01')) {
        $platform = new \BrowserDetector\Detector\Os\Windows2000($useragent, '5.01');

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== strpos($useragent, 'Windows NT 5.0')) {
        $platform = new \BrowserDetector\Detector\Os\Windows2000($useragent, '5.0');

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== strpos($useragent, 'Windows NT 4.10')) {
        $platform = new \BrowserDetector\Detector\Os\WindowsNt($useragent, '4.10');

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== strpos($useragent, 'Windows NT 4.1')) {
        $platform = new \BrowserDetector\Detector\Os\WindowsNt($useragent, '4.1');

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== strpos($useragent, 'Windows NT 4.0')) {
        $platform = new \BrowserDetector\Detector\Os\WindowsNt($useragent, '4.0');

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== strpos($useragent, 'Windows NT 3.5')) {
        $platform = new \BrowserDetector\Detector\Os\WindowsNt($useragent, '3.5');

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== strpos($useragent, 'Windows NT 3.1')) {
        $platform = new \BrowserDetector\Detector\Os\WindowsNt($useragent, '3.1');

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== strpos($useragent, 'Windows[ \-]NT')) {
        $platform = new \BrowserDetector\Detector\Os\WindowsNt($useragent, '0.0');

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== stripos($useragent, 'cygwin')) {
        $platform = new \BrowserDetector\Detector\Os\Cygwin($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== strpos($useragent, 'CPU OS')) {
        $platform = new \BrowserDetector\Detector\Os\Ios($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== stripos($useragent, 'CPU iPhone OS')) {
        $platform = new \BrowserDetector\Detector\Os\Ios($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== strpos($useragent, 'CPU like Mac OS X')) {
        $platform = new \BrowserDetector\Detector\Os\Ios($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== strpos($useragent, 'iOS')) {
        $platform = new \BrowserDetector\Detector\Os\Ios($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== strpos($useragent, 'Mac OS X')) {
        $platform = new \BrowserDetector\Detector\Os\Macosx($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== strpos($useragent, 'hpwOS')) {
        $platform = new \BrowserDetector\Detector\Os\WebOs($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== strpos($useragent, 'Debian APT-HTTP')) {
        $platform = new \BrowserDetector\Detector\Os\Debian($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (preg_match('/linux arm/i', $useragent)) {
        $platform = new \BrowserDetector\Detector\Os\Maemo($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== stripos($useragent, 'fedora')) {
        $platform = new \BrowserDetector\Detector\Os\Fedora($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== stripos($useragent, 'suse')) {
        $platform = new \BrowserDetector\Detector\Os\Suse($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== stripos($useragent, 'centos')) {
        $platform = new \BrowserDetector\Detector\Os\CentOs($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== stripos($useragent, 'mandriva')) {
        $platform = new \BrowserDetector\Detector\Os\Mandriva($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== stripos($useragent, 'gentoo')) {
        $platform = new \BrowserDetector\Detector\Os\Gentoo($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== stripos($useragent, 'slackware')) {
        $platform = new \BrowserDetector\Detector\Os\Slackware($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== strpos($useragent, 'CrOS')) {
        $platform = new \BrowserDetector\Detector\Os\CrOs($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== stripos($useragent, 'debian')) {
        $platform = new \BrowserDetector\Detector\Os\Debian($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== stripos($useragent, 'android; linux arm')) {
        $platform = new \BrowserDetector\Detector\Os\AndroidOs($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (preg_match('/(maemo|like android|linux\/x2\/r1|linux arm)/i', $useragent)) {
        $platform = new \BrowserDetector\Detector\Os\Maemo($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== stripos($useragent, 'moblin')) {
        $platform = new \BrowserDetector\Detector\Os\Moblin($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== stripos($useragent, 'infegyatlas') || false !== stripos($useragent, 'jobboerse')) {
        $platform = new \BrowserDetector\Detector\Os\UnknownOs($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (preg_match('/Puffin\/[\d\.]+(A|I|W|M)(T|P)?/', $useragent)) {
        $platform = new \BrowserDetector\Detector\Os\UnknownOs($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== strpos($useragent, 'Linux')) {
        $platform = new \BrowserDetector\Detector\Os\Linux($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (false !== strpos($useragent, 'SymbOS')) {
        $platform = new \BrowserDetector\Detector\Os\Symbianos($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } elseif (preg_match('/CFNetwork/', $useragent)) {
        $platform = \BrowserDetector\Detector\Factory\Platform\DarwinFactory::detect($useragent);

        $platformCodename      = $platform->getName();
        $platformMarketingname = $platform->getMarketingName();
        $platformVersion       = $platform->getVersion()->getVersion();
        $platformBits          = $platform->getBits();
        $platformMaker         = $platform->getManufacturer();
        $platformBrandname     = $platform->getBrand();
    } else {
        $result = $detector->getBrowser($useragent);

        $platform = $result->getOs();

        if ($platformCodename === $platform->getName()) {
            $platformMarketingname = $platform->getMarketingName();
            $platformVersion       = $platform->getVersion()->getVersion();
            $platformBits          = $platform->getBits();
            $platformMaker         = $platform->getManufacturer();
            $platformBrandname     = $platform->getBrand();
        }
    }

    return [
        $platformCodename,
        $platformMarketingname,
        $platformVersion,
        $platformBits,
        $platformMaker,
        $platformBrandname,
        $platform,
    ];
}

function rewriteDevice(array $test, \BrowserDetector\BrowserDetector $detector, \UaResult\Os\OsInterface $platform)
{
    if (isset($test['properties']['Device_Name'])) {
        $deviceName = $test['properties']['Device_Name'];
    } else {
        $deviceName = 'unknown';
    }

    if (isset($test['properties']['Device_Maker'])) {
        $deviceMaker = $test['properties']['Device_Maker'];
    } else {
        $deviceMaker = 'unknown';
    }

    if (isset($test['properties']['Device_Type'])) {
        $deviceType = $test['properties']['Device_Type'];
    } else {
        $deviceType = 'unknown';
    }

    if (isset($test['properties']['Device_Pointing_Method'])) {
        $devicePointing = $test['properties']['Device_Pointing_Method'];
    } else {
        $devicePointing = 'unknown';
    }

    if (isset($test['properties']['Device_Code_Name'])) {
        $deviceCode = $test['properties']['Device_Code_Name'];
    } else {
        $deviceCode = 'unknown';
    }

    if (isset($test['properties']['Device_Brand_Name'])) {
        $deviceBrand = $test['properties']['Device_Brand_Name'];
    } else {
        $deviceBrand = 'unknown';
    }

    if (isset($test['properties']['Device_Dual_Orientation'])) {
        $deviceOrientation = $test['properties']['Device_Dual_Orientation'];
    } else {
        $deviceOrientation = 'unknown';
    }

    $useragent = $test['ua'];

    if (preg_match('/redmi 3s/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Xiaomi\XiaomiRedmi3s($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/redmi 3/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Xiaomi\XiaomiRedmi3($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Redmi Note 2/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Xiaomi\XiaomiRedmiNote2($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Redmi_Note_3/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Xiaomi\XiaomiRedmiNote3($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/mi max/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Xiaomi\XiaomiMiMax($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/mi 4lte/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Xiaomi\XiaomiMi4lte($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/mi pad/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Xiaomi\XiaomiMiPad($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/(rm\-1113|lumia 640 lte)/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaLumia640lte($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/(rm\-1075|lumia 640 dual sim)/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaLumia640lteRm1075($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/rm\-1067/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaLumia640xlRm1067($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/rm\-1090/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaLumia535Rm1090($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/rm\-1089/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaLumia535Rm1089($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/rm\-1038/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaLumia735Rm1038($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/(rm\-1031|lumia 532)/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaLumia532($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/rm\-994/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaLumia1320Rm994($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/rm\-1010/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaLumia638($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/rm\-1045/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaLumia930Rm1045($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/rm\-976/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaLumia630Rm976($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/rm\-978/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaLumia630Rm978($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/rm\-997/i', $useragent) && !preg_match('/(nokia|microsoft)/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\RossMoor\RossMoorRm997($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/rm\-997/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaLumia526($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/rm\-560/i', $useragent) && !preg_match('/(nokia|microsoft)/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\RossMoor\RossMoorRm560($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/lumia 720/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaLumia720($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/lumia 735/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaLumia735($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/lumia 730/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaLumia730($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/lumia 521/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaLumia521($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/lumia 520/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaLumia520($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/lumia 535/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaLumia535($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/lumia 530/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaLumia530($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/lumia 540/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaLumia540($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/lumia 1320/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaLumia1320($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/lumia 930/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaLumia930($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/lumia 950 xl/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaLumia950xl($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/lumia 950/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaLumia950($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/lumia 920/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaLumia920($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/lumia 640 xl/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaLumia640xl($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/lumia 635/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaLumia635($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/lumia 710/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaLumia710($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/lumia 610/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaLumia610($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/(lumia 1020|nokia; 909|arm; 909)/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaLumia1020($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/lumia 1520/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaLumia1520($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/lumia 435/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaLumia435($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/lumia 800/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaLumia800($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/lumia 900/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaLumia900($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/lumia 630/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaLumia630($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/lumia 510/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaLumia510($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/lumia 550/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaLumia550($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/(lumia|nokia) 925/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaLumia925($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/lumia 928/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaLumia928($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/lumia 620/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaLumia620($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/lumia 625/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaLumia625($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/lumia 820/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaLumia820($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/lumia 830/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaLumia830($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/(lumia 650|id336)/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaLumia650($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/8X by HTC/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\Htc8x($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/8S by HTC/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\Htc8s($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/radar( c110e|; orange)/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcC110eRadar($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/HUAWEI; W1\-U00/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiW1u00($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/HUAWEI; W2\-U00/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiW2u00($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/one[_ ]m9plus/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcOneM9plus($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/one[_ ]m9/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcOneM9($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/one[_ ]m8s/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcOneM8s($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/one[_ ]m8/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcOneM8($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/pn07120/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcOneM7GooglePlay($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/(one[ _]max|himauhl_htc_asia_tw)/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcOneMax($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/one[ _]mini[ _]2/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcOneMini2($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/one[_ ]mini/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcOneMini($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/(one[ _]sv|onesv)/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcOneSv($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/(one[ _]s|ones)/i', $useragent) && !preg_match('/iOS/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcOneS($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/(one[ _]x\+|onexplus)/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcOneXplus($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/one[ _]xl/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcOneXl($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/(one[ _]x|onex|PJ83100)/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcOneX($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/(one[ _]v|onev)/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcOneV($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/(PC36100|EVO 4G)/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcEvo4g($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Evo 3D GSM/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcEvo3dGsm($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/HTC T328d/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcT328d($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/HTC T328w/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcT328w($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/HTC T329d/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcT329d($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/HTC 919d/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\Htc919d($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/HTC D820us/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcD820us($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/HTC D820mu/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcD820mu($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/HTC 809d/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\Htc809d($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/HTC[ ]?802t/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\Htc802T($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/HTC 606w/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\Htc606w($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/HTC D516d/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcDesire516($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/HTC Butterfly/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcButterfly($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/desire[ _]820s/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcDesire820s($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/desire[ _]820/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcDesire820($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/desire[ _]816g/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcDesire816g($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/desire[ _]816/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcDesire816($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/desire[ _]728g/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcDesire728g($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/desire[ _]700/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcDesire700($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/desire[ _]610/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcDesire610($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/desire[ _]600c/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcDesire600c($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/desire[ _]600/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcDesire600($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/desire[ _]626g/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcDesire626g($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/desire[ _]626/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcDesire626($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/desire[ _]620g/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcDesire620g($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/desire[ _]526g/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcDesire526g($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/desire[ _]500/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcDesire500($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/desire[ _]510/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcDesire510($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/desire[ _]530/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcDesire530($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/desire[ _]400/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcDesire400($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/desire[ _]320/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcDesire320($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/desire[ _]310/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcDesire310($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/desire[ _]300/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcDesire300($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/(0p4e2|desire[ _]601)/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\Htc0p4e2($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/desire[ _]eye/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcDesireEye($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/desire_a8181/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcA8181Desire($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/desirez\_a7272/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcDesireZA7272($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/(desire[ _]z|desirez)/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcDesireZ($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/s510e/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcS510eDesireS($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/(desire[ _]sv|desiresv)/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcDesireSv($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/(desire[ _]s|desires)/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcDesireS($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/a9191/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcA9191DesireHd($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/(desire hd|desirehd)/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcDesireHd($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/apa9292kt/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcA9292Apa9292kt($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/a9192/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcA9192Inspire4g($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/(hd7|mondrian)/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcT9292($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/(pc36100|evo 4g|kingdom)/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcEvo4g($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/htc_ruby/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcRuby($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/nexus 9/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcNexus9($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Nexus One/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\GalaxyNexusOne($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/htc_amaze/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcAmaze($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/htc_butterfly_s_901s/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcS901s($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/HTC[ _]Sensation[ _]4G/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcSensation4g($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/sensation[ _]z710e/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcZ710e($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/(sensation|pyramid)/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcZ710($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/genm14/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaXl2($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Nokia_XL/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaXl($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/NokiaX3\-02/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaX302($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/NokiaX6\-00/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaX600($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/NokiaX2\-00/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaX200($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/NokiaX2\-01/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaX201($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/NokiaX2\-02/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaX202($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/NokiaX2\-05/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaX205($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/nokia300/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\Nokia300($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/nokia200/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\Nokia200($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/nokia203/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\Nokia203($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/nokia210/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\Nokia210($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/nokia206/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\Nokia206($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/nokia205/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\Nokia205($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/(nokia500|nokiaasha500)/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\Nokia500($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/nokia501/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\Nokia501($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Nokia5800d/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\Nokia5800XpressMusic($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Nokia5230/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\Nokia5230($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/NokiaC2\-01/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaC201($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/NokiaC6\-01/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaC601($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/NokiaC6\-00/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaC600($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/NokiaC5\-00/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaC500($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/NokiaN8\-00/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaN800($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/NokiaN82/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaN82($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/NokiaN95/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaN95($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/nokia ?n70/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\NokiaN70($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/NOKIA6700s/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\Nokia6700s($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/NOKIA6700c/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\Nokia6700c($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/NOKIA6120c/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\Nokia6120c($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/NokiaE71\-1/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\Nokiae711($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/NokiaE71/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\Nokiae71($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/nokia7230/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nokia\Nokia7230($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/L50u/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\SonyEricsson\SonyL50uExperiaZ2lte($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SonyEricssonS312/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\SonyEricsson\SonyEricssonS312($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/(Xperia Z|C6603)/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\SonyEricsson\SonyC6603ExperiaZ($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/C6602/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\SonyEricsson\SonyC6602ExperiaZ($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/C6606/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\SonyEricsson\SonyC6606ExperiaZ($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LT26ii/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\SonyEricsson\SonyEricssonLT26ii($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LT26i/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\SonyEricsson\SonyEricssonLT26i($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LT26w/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\SonyEricsson\SonyLT26w($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LT30p/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\SonyEricsson\SonyLT30p($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/ST26i/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\SonyEricsson\SonyST26i($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/D6603/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\SonyEricsson\SonyD6603ExperiaZ3($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/D6503/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\SonyEricsson\SonyD6503ExperiaZ2($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/D5803/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\SonyEricsson\SonyD5803XperiaZ3Compact($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/D5103/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\SonyEricsson\SonyD5103($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/D2005/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\SonyEricsson\SonyD2005($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/D2203/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\SonyEricsson\SonyD2203($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/D2403/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\SonyEricsson\SonyD2403($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/C5303/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\SonyEricsson\SonyC5303XperiaSp($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/C6903/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\SonyEricsson\SonyC6903ExperiaZ1($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/C1905/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\SonyEricsson\SonyC1905($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/C2105/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\SonyEricsson\SonyC2105XperiaL($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SGP512/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\SonyEricsson\SonyTabletSgp512($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SGP521/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\SonyEricsson\SonyTabletSgp521($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SGP511/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\SonyEricsson\SonyTabletSgp511($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SGP771/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\SonyEricsson\SonyTabletSgp771($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SGP412/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\SonyEricsson\SonyTabletSgp412($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/E5823/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\SonyEricsson\SonyE5823($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/E2303/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\SonyEricsson\SonyE2303($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/E2003/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\SonyEricsson\SonyE2003($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/F3111/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\SonyEricsson\SonyF3111($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/E6653/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\SonyEricsson\SonyE6653($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/E6553/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\SonyEricsson\SonyE6553($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SO\-01E/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\SonyEricsson\SonyEricssonSo01e($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SO\-01D/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\SonyEricsson\SonyEricssonSo01d($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SO\-01C/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\SonyEricsson\SonyEricssonSo01c($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SO\-01B/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\SonyEricsson\SonyEricssonSo01b($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/ONEPLUS A3000/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Oneplus\OneplusA3000($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/ONE E1003/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Oneplus\OneplusE1003($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/ONE A2005/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Oneplus\OneplusA2005($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/ONE A2003/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Oneplus\OneplusA2003($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/ONE A2001/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Oneplus\OneplusA2001($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/MZ\-MX5/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Meizu\MeizuMx5($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G9006V/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG9006v($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G900F/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG900F($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G900a/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG900a($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G900h/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG900h($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G900i/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG900i($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G900t/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG900T($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G900v/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG900V($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G900w8/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG900w8($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G900/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG900($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G903F/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG903F($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G901F/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG901F($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G928F/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG928F($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G928C/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG928C($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G928P/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG928P($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G928V/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG928V($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G928G/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG928G($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G928I/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG928I($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G928W8/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG928W8($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G9287/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG9287($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G925F/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG925F($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G925I/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG925I($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G925P/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG925P($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G925T/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG925T($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G925R4/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG925R4($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G925K/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG925k($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G920V/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG920V($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G920L/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG920L($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G920P/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG920P($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G920K/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG920K($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G920FD/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG920Fd($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G920F/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG920F($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G920S/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG920S($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G920I/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG920I($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G920A/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG920A($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G920T1/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG920T1($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G920T/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG920T($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G9200/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG9200($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G9208/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG9208($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G9209/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG9209($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G920W8/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG920W8($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G920R/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG920R($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G930FD/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG930FD($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G930F/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG930F($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G930A/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG930A($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G930R/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG930R($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G930V/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG930V($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G930P/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG930P($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G930T/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG930T($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G9308/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG9308($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G930/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG930($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G935FD/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG935FD($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G935F/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG935F($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G935A/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG935A($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G935P/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG935P($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G935R/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG935R($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G935T/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG935T($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G935V/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG935V($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G935K/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG935K($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G935L/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG935L($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G935S/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG935S($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G935W8/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG935W8($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G935X/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG935X($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G9350/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG9350($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/sm\-g850fq/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG850fq($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/(sm\-g850f|galaxy alpha)/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG850F($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/sm\-g850a/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG850a($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/sm\-g850m/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG850m($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/sm\-g850t/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG850t($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/sm\-g850w/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG850w($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/sm\-g850y/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG850y($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G870A/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG870a($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G870F/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG870F($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/sm\-g800hq/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG800HQ($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/sm\-g800h/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG800H($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/sm\-g800f/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG800F($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/sm\-g800m/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG800M($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/sm\-g800a/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG800A($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/sm\-g800r4/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG800R4($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/sm\-g800y/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG800Y($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G890A/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG890a($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G530H/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG530h($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G530T/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG530t($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G530FZ/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG530fz($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G530F/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG530f($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G530BT/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG530bt($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G530M/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG530m($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G530Y/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG530y($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G5306W/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG5306w($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G5308W/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG5308w($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G531F/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG531f($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G531H/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG531h($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G388F/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG388F($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G389F/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG389F($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G386F/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG386F($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G3815/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG3815($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G360HU/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG360HU($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G360H/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG360H($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G360T1/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG360T1($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G360T/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG360T($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G360G/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG360G($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G360F/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG360F($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G360BT/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG360BT($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G361F/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG361F($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G361H/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG361h($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G313HU/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG313hu($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G313HN/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG313hn($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G310HN/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG310hn($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G318H/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG318h($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G355HQ/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG355hq($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G355HN/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG355hn($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G355H/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG355h($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G355M/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG355m($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G350e/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG350e($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G3500/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG3500($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G3502L/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG3502l($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G3502T/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG3502t($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G350/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG350($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G357fz/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG357fz($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G130H/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG130H($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G110H/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG110H($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G720N0/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG720n0($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G720D/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG720d($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G7202/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG7202($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G710L/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG710L($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G7102T/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG7102T($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G7102/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG7102($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G7105L/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG7105L($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G7105/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG7105($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G7106/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG7106($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G7108V/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG7108V($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G7108/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG7108($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G7109/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG7109($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-G710/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmG710($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T110/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT110($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T113/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT113($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T116/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT116($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T111/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT111($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T2105/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT2105($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T210l/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT210l($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T210r/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT210r($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T210/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT210($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T211/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT211($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T230NU/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT230nu($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T230/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT230($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T231/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT231($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T280/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT280($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T525/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT525($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T520/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT520($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T580/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT580($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T585/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT585($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T550x/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT550x($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T550/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT550($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T555/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT555($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T560/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT560($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T561/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT561($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T530nu/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT530nu($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T530/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT530($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T531/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT531($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T535/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT535($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T533/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT533($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T810x/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT810x($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T810/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT810($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T815y/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT815y($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T815/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT815($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T813/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT813($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T819/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT819($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T805/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT805($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T800/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT800($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T315/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT315($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T311/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT311($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T310/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT310($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T320/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT320($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T325/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT325($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T335/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT335($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T331/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT331($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T330/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT330($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T350/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT350($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T355Y/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT355y($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T365/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT365($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T710/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT710($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T715/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT715($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T719/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT719($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T713/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT713($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T700/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT700($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T705m/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT705m($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T705/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT705($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T900/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT900($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-T670/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmT670($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-C101/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmC101($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-C105/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmC105($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-C115/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmC115($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-C111/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmC111($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N9005/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN9005($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N9002/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN9002($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N9008V/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN9008V($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N9009/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN9009($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N9007/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN9007($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N9006/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN9006($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N900A/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN900A($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N900V/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN900V($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N900K/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN900K($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N900S/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN900S($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N900T/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN900T($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N900P/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN900P($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N900L/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN900L($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N900W8/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN900W8($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N9000Q/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN9000Q($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N900/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN900($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N910FQ/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN910FQ($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N910FD/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN910FD($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N910F/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN910F($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N910A/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN910A($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N910C/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN910C($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N910G/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN910G($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N910H/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN910H($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N910K/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN910K($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N910L/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN910L($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N910M/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN910M($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N910R4/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN910R4($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N910P/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN910P($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N910S/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN910S($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N910T1/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN910T1($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N910T3/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN910T3($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N910T/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN910T($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N910U/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN910U($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N910V/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN910V($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N910W8/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN910W8($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N910X/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN910X($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N9100H/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN9100H($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N9100/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN9100($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N915G/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN915g($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N915T/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN915t($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N915D/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN915d($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N915K/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN915k($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N915L/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN915l($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N915S/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN915s($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N915P/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN915p($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N915FY/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN915fy($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N915F/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN915f($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N915A/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN915a($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N915V/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN915v($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N9150/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN9150($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N916S/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN916s($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N930FD/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN930FD($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N930F/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN930F($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N930U/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN930U($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N930W8/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN930W8($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N9300/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN9300($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N9308/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN9308($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N930K/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN930K($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N930L/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN930L($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N930S/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN930S($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N930AZ/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN930AZ($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N930A/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN930A($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N930P/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN930P($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N930V/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN930V($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N930T1/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN930T1($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N930T/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN930T($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N930R4/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN930R4($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N930R4/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN930R4($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N930R6/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN930R6($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N930R7/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN930R7($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N920V/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN920V($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N920T/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN920T($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N920P/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN920P($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N920A/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN920A($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N920W8/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN920W8($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N9208/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN9208($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N9200/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN9200($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N920I/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN920I($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N920C/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN920C($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N920G/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN920G($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N920K/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN920K($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N920L/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN920L($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N920S/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN920S($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N920R/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN920R($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N7505L/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN7505L($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N7505/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN7505($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N7502/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN7502($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N7500Q/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN7500Q($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-N750/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmN750($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-E500H/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmE500H($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-E700F/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmE700f($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-E700H/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmE700h($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-E700M/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmE700m($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-E7000/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmE7000($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-E7009/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmE7009($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-A500FU/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmA500fu($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-A500F/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmA500f($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-A500H/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmA500h($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-A500L/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmA500l($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-A500Y/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmA500y($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-A5000/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmA5000($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-A300FU/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmA300fu($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-A300F/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmA300f($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-A300H/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmA300h($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-A310F/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmA310f($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-A510FD/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmA510fd($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-A510F/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmA510f($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-A510M/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmA510m($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-A510Y/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmA510y($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-A5100/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmA5100($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-A700FD/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmA700fd($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-A700F/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmA700f($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-A700S/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmA700s($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-A700K/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmA700k($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-A700L/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmA700l($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-A700H/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmA700h($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-A700YD/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmA700yd($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-A7000/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmA7000($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-A7009/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmA7009($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-A710FD/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmA710fd($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-A710F/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmA710f($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-A710M/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmA710m($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-A7100/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmA7100($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-A710Y/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmA710y($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-A800F/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmA800f($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-A800Y/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmA800y($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-A800I/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmA800i($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-A8000/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmA8000($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-A9000/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmA9000($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-J510FN/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmJ510fn($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-J510F/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmJ510f($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-J500FN/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmJ500fn($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-J500F/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmJ500f($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-J500G/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmJ500g($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-J500Y/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmJ500y($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-J500M/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmJ500m($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-J500H/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmJ500h($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-J5007/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmJ5007($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-J320g/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmJ320g($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-J320fn/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmJ320fn($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-J320f/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmJ320f($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-J3109/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmJ3109($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-J100H/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmJ100h($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-J100Y/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmJ100y($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-J100F/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmJ100f($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-J105H/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmJ105h($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-J100ML/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmJ100ml($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-J110F/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmJ110f($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-J110G/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmJ110G($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-J110H/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmJ110h($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-J110L/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmJ110l($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-J110M/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmJ110m($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-J111F/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmJ111f($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-J120FN/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmJ120fn($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-J120F/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmJ120f($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-J120G/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmJ120g($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-J120H/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmJ120h($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-J120M/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmJ120m($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-J200GU/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmJ200gu($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-J200G/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmJ200g($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-J200F/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmJ200f($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-J200H/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmJ200h($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-J200BT/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmJ200bt($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-J200Y/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmJ200y($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-J700F/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmJ700f($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-J700M/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmJ700m($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-J700H/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmJ700h($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-J710FN/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmJ710fn($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-J710F/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmJ710f($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-J710H/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmJ710h($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-J710M/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmJ710m($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-P600/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmP600($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-P601/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmP601($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-P605/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmP605($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-P550/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmP550($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-P901/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmP901($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-P900/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmP900($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-P907A/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmP907a($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-P905M/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmP905m($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-P905V/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmP905v($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-P905/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmP905($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-P355/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmP355($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-P350/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmP350($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-Z130H/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmZ130H($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-S820L/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmS820l($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SM\-B550H/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmB550h($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Nexus Player/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGalaxyNexusPlayer($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/NEO\-X5/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Minix\MinixNeoX5($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/vns\-l31/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiVnsL31($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/vns\-l21/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiVnsL21($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/tit\-u02/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiTitU02($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/g750\-u10/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiG750u10($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/g750\-t00/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiG750t00($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/g730\-u10/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiG730u10($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/G510\-0100/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiG5100100($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/G525\-U00/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiG525U00($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/MediaPad 7 Youth/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiMediaPad7Youth($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/MediaPad 7 Lite/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiMediaPad7Lite($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/PE\-TL10/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiPetl10($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/P6\-U06/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiP6U06($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/HUAWEI G6\-L11/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiG6L11($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/HUAWEI G6\-U10/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiG6u10($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/G7\-L01/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiG7l01($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/G7\-L11/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiG7l11($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/G700\-U10/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiG700U10($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/G740\-L00/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiG740l00($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/HUAWEI P7\-L10/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiP7L10($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/HUAWEI P7\-L09/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiP7L09($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/(p7 mini|p7mini)/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiP7mini($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/HUAWEI P2\-6011/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiP26011($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/HUAWEI SCL\-L01/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiSclL01($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/HUAWEI SCL\-L21/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiSclL21($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/HUAWEI SCL\-U31/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiSclu31($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/HUAWEI NXT\-L29/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiNxtL29($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/HUAWEI NXT\-AL10/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiNxtAl10($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/GEM\-701L/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiGem701l($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/GEM\-702L/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiGem702l($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/GEM\-703L/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiGem703l($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/G620S\-L01/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiG620sL01($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/HUAWEI G610\-U20/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiG610u20($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/G630\-U20/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiG630u20($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/EVA\-L09/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiEvaL09($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/EVA\-L19/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiEvaL19($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/mediapad 10 link\+/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiMediaPad10LinkPlus($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/mediapad 10 link/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiMediaPad10Link($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/mediapad 10 fhd/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiMediaPad10fhd($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/MediaPad T1 8\.0/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiMediaPadT180($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/MediaPad M1 8\.0/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiMediaPadM18($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/u8651t/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiU8651t($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/u8651s/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiU8651s($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/u8651/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiU8651($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/HUAWEI U8666 Build\/HuaweiU8666E/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiU8666($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/u8666e/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiU8666e($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/u8666/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiU8666($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/U8950d/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiU8950d($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/U8950n\-1/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiU8950n1($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/U8950n/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiU8950n($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/U8950\-1/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiU89501($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/U8950/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiU8950($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/U9200/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiU9200($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/U8860/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiU8860($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Huawei Y511/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiY511($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Y600\-U00/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiY600u00($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Y600\-U20/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiY600u20($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Y635\-L21/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiY635l21($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Y625\-U51/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiY625u51($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Huawei Y530\-U00/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiY530u00($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Y320\-U30/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiY320u30($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Y320\-U10/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiY320u10($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Y330\-U11/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiY330u11($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Y336\-U02/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiY336u02($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Y360\-U61/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiY360u61($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Y330\-U05/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiY330u05($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Y330\-U01/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiY330u01($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Y220\-U10/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiY220u10($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/HUAWEI Y300/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiY300($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/HUAWEI ALE\-21/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiAle21($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/ALE\-L21/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiAlel21($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/ALE\-L02/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiAlel02($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/H30\-U10/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiH30u10($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/KIW\-L21/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiKiwl21($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/TAG\-AL00/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiTagal00($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/TAG\-L21/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiTagl21($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/GRA\-L09/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiGraL09($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/GRACE/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiGrace($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/S8\-701w/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiS8701w($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/MT7\-TL10/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiMt7Tl10($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/(MT7\-L09|JAZZ)/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiMt7L09($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/MT1\-U06/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiMt1U06($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/D2\-0082/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiD20082($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/HN3\-U01/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiHn3u01($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/HOL\-U19/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiHolu19($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/(t1\-701u|t1 7\.0)/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiT1701u($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/T1\-a21l/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiT1a21l($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/T1\-a21w/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiT1a21w($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/FDR\-a01l/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiFdra01l($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/M2\-a01l/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiM2a01l($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/M2\-a01w/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiM2a01w($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/M2\-801w/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiM2801w($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/M2\-801l/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiM2801l($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/RIO\-L01/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiRiol01($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/CRR\-L09/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiCrrl09($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/CHC\-U01/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiChcu01($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/ATH\-UL01/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiAthul01($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Y550\-L01/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiY550l01($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Y560\-L01/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiY560l01($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Y540\-U01/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiY540u01($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Y210\-0100/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiY2100100($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/F5281/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Hisense\HisenseF5281($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Aquaris M10/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Bq\BqAquarisM10($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Aquaris M5/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Bq\BqAquarisM5($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Aquaris[ _]M4\.5/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Bq\BqAquarisM45($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Aquaris E5 HD/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Bq\BqAquarisE5hd($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/BQS\-4005/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Bq\Bq4005($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/BQS\-4007/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Bq\Bq4007($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i9195i/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti9195i($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i9195/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti9195($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i9190/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti9190($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i9192/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti9192($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i9100g/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti9100g($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i9100p/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti9100p($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i9100/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti9100($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i9105p/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti9105p($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i9105/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti9105($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i9103/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti9103($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i9152/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti9152($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i9300i/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti9300i($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i9300/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti9300($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i9301i/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti9301i($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i9301q/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti9301q($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i9301/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti9301($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i9305/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti9305($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i9060i/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti9060i($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i9060l/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti9060l($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i9060/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti9060($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i9070p/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti9070P($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i9070/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti9070($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i9003l/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti9003l($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i9003/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti9003($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i9001/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti9001($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i9000/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti9000($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i9082L/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti9082L($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i9082/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti9082($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i9505g/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti9505g($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i9505x/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti9505x($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i9505/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti9505($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i9506/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti9506($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i9502/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti9502($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i9500/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti9500($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i9502/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti9502($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i9515/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti9515($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i9295/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti9295($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i9205/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti9205($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i9200/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti9200($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i5500/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti5500($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i9515/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti9515($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i5500/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti5500($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i5700/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti5700($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i8190n/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti8190n($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i8190/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti8190($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i8150/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti8150($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i8160p/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti8160p($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i8160/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti8160($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i8200n/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti8200n($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i8200/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti8200($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i8260/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti8260($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i8262/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti8262($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i8552/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti8552($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i8530/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti8530($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/(gt\-i8910|i8910)/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti8910($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i8730/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti8730($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-i8750/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti8750($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-e3309t/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGte3309t($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-e2202/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGte2202($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-e2252/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGte2252($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-b7722/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGtb7722($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-s7262/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGts7262($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-s7275r/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGts7275r($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-s7275/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGts7275($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-s7272/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGts7272($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-s7270/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGts7270($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-s7500/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGts7500($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-s7580/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGts7580($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-s7582/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGts7582($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-s7562l/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGts7562l($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-s7562/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGts7562($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-s7560/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGts7560($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-s7392/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGts7392($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-s7390/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGts7390($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-s7710/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGts7710($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-s3802/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGts3802($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-s3653/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGts3653($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-s5620/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGts5620($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-s5660/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGts5660($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-s5301L/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGts5301l($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-s5301/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGts5301($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-s5302/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGts5302($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-s5300b/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGts5300B($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-s5300/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGts5300($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-s5310m/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGts5310m($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-s5310/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGts5310($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-s5360/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGts5360($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-s5363/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGts5363($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-s5369/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGts5369($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-s5830l/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGts5830l($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-s5830i/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGts5830i($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-s5830c/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGts5830c($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-s6810b/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGts6810b($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-s6810p/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGts6810p($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-s6810/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGts6810($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-s6500t/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGts6500t($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-s6500d/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGts6500d($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-s6500/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGts6500($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-s6312/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGts6312($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-s6310n/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGts6310N($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-s6310/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGts6310($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-s6102b/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGts6102B($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-s6102/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGts6102($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-s5830/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGts5830($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-s5839i/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGts5839i($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-s5570/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGts5570($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-s5280/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGts5280($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-s5220/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGts5220($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-s5233s/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGts5233S($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-c6712/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGtc6712($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-c3262/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGtc3262($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-c3322/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGtc3322($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-c3780/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGtc3780($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-p5110/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGtp5110($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-p5100/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGtp5100($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-P5210/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGtp5210($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-P5200/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGtp5200($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-P5220/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGtp5220($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-p7510/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGtp7510($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-p7511/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGtp7511($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-p7500M/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGtp7500M($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-p7500/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGtp7500($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-p7501/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGtp7501($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-p7100/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGtp7100($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-p7310/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGtp7310($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-p3100/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGtp3100($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-p3110/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGtp3110($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-p3113/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGtp3113($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-p1010/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGtp1010($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-p1000m/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGtp1000M($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-p1000n/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGtp1000N($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-p1000/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGtp1000($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-p6201/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGtp6201($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-p6211/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGtp6211($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-p6200/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGtp6200($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-n7100/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGtn7100($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-n7105/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGtn7105($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-n7000/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGtn7000($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-n5110/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGtn5110($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-n5100/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGtn5100($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-n5120/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGtn5120($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-n8010/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGtn8010($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-n8013/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGtn8013($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-n8020/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGtn8020($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-n8005/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGtn8005($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/N8000D/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGtn8000d($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-n8000/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGtn8000($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/sgh\-e250i/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSghE250i($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/sgh\-e250/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSghE250($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/sgh\-t528g/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSght528g($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/sgh\-t989d/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSghT989d($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/sgh\-t989/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSghT989($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/sgh\-t999/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSghT999($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/sgh\-t959v/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSghT959v($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/sgh\-t959/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSghT959($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/sgh\-t839/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSghT839($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/sgh\-t859/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSghT859($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/sgh\-t889/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSghT889($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/sgh\-t899m/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSghT899m($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/sgh\-i257/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSghi257($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SGH\-I717/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSghi717($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SGH\-I727R/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSghi727r($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SGH\-I727/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSghi727($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SGH\-I317/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSghi317($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SGH\-I337m/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSghi337m($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SGH\-I337/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSghi337($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SGH\-I467/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSghi467($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SGH\-I897/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSghi897($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/sgh\-m919/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSghm919($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/sch\-r970/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSchr970($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/sch\-r950/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSchr950($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/sch\-r530u/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSchR530u($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/sch\-r530c/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSchR530c($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/sch\-i815/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSchI8154g($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/sch\-i545/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSchI545($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/sch\-i535/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSchI535($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/sch\-i605/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSchI605($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/sch\-i435/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSchI435($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/sch\-i400/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSchI400($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/sch\-n719/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSchN719($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/sc\-02f/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSc02f($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/sc\-02c/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSc02c($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/sc\-02b/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSc02b($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/sc\-01f/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSc01f($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/sc\-06d/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSc06d($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/shv\-e210l/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungShvE210l($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/shv\-e210s/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungShvE210s($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/shv\-e210k/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungShvE210k($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/shv\-e250l/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungShvE250l($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/shv\-e250k/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungShvE250k($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/shv\-e250s/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungShvE250s($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/shv\-e160s/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungShvE160s($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/shv\-e370k/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungShvE370k($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/shw\-m480w/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungShwM480W($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/shw\-m180s/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungShwM180s($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/sph\-m840/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSphm840($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/sph\-m930/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSphm930($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/sph\-l900/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSphl900($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/sph\-l720/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSphl720($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/sph\-l710/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSphl710($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/nexus 10/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGalaxyNexus10($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Nexus S 4G/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGalaxyNexusS4G($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Nexus S/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGalaxyNexusS($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/samsung galaxy s4/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti9500($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/gt\-9000/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Star\StarGt9000($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Slate 17/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Hp\HpSlate17($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/H345/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lg\LgH345($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/H340n/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lg\LgH340n($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/H320/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lg\LgH320($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/H850/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lg\LgH850($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LG\-D802TR/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lg\Lgd802tr($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LG\-D802/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lg\Lgd802($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LG\-D855/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lg\Lgd855($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LG\-D856/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lg\Lgd856($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LG\-D320/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lg\Lgd320($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LG\-D325/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lg\Lgd325($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LG\-D373/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lg\Lgd373($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LG\-D290/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lg\Lgd290($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LG\-D955/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lg\Lgd955($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LG\-D958/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lg\Lgd958($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LG\-D686/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lg\LgD686($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LG\-D682tr/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lg\LgD682tr($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LG\-D682/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lg\LgD682($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LG\-D690/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lg\LgD690($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LG\-D620/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lg\LgD620($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LG\-D415/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lg\Lgd415($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LG\-D410/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lg\Lgd410($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LG\-E425/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lg\Lge425($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LG\-E612/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lg\Lge612($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LG\-E610/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lg\Lge610($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LG\-E615/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lg\Lge615($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LG\-E460/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lg\Lge460($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LG\-E988/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lg\Lge988($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LG\-E989/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lg\Lge989($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LG\-F240K/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lg\LgF240k($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LG\-F220K/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lg\LgF220K($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LG\-F200K/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lg\LgF200K($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LG\-V935/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lg\Lgv935($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LG\-V490/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lg\Lgv490($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LG\-X150/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lg\Lgx150($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LG\-P765/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lg\Lgp765($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LG\-P970/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lg\Lgp970($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LG\-H525n/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lg\LgH525n($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/nexus 5x/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lg\LgNexus5x($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/nexus ?5/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lg\LgNexus5($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/nexus ?4/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lg\LgNexus4($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LIFETAB_E10316/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Medion\MdLifetabE10316($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LIFETAB_E10312/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Medion\MdLifetabE10312($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LIFETAB_E10320/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Medion\MdLifetabE10320($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LIFETAB_E10310/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Medion\MdLifetabE10310($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LIFETAB_E7312/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Medion\MdLifetabE7312($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LIFETAB_E7316/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Medion\MdLifetabE7316($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LIFETAB_E7313/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Medion\MdLifetabE7313($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LIFETAB_E733X/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Medion\MdLifetabE733x($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LIFETAB_E723X/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Medion\MdLifetabE723x($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LIFETAB_P733X/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Medion\MdLifetabP733x($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LIFETAB_P1034X/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Medion\MdLifetabP1034x($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LIFETAB_P891X/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Medion\MdLifetabP891x($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LIFETAB_P831X\.2/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Medion\MdLifetabP831x2($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LIFETAB_P831X/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Medion\MdLifetabP831x($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LIFETAB_P9516/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Medion\MdLifetabP9516($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LIFETAB_P9514/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Medion\MdLifetabP9514($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LIFETAB_S1034X/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Medion\MdLifetabS1034x($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LIFETAB_S1033X/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Medion\MdLifetabS1033x($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LIFETAB_S1036X/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Medion\MdLifetabS1036x($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LIFETAB_S9714/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Medion\MdLifetabS9714($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LIFETAB_S9512/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Medion\MdLifetabS9512($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LIFETAB_S831X/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Medion\MdLifetabS831x($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LIFETAB_S785X/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Medion\MdLifetabS785x($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LIFETAB_S732X/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Medion\MdLifetabS732x($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/P4501/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Medion\MdLifeP4501($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/P4502/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Medion\MedionP4502($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/P4013/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Medion\MdLifeP4013($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/MEDION E5001/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Medion\MdLifeE5001($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/(MEDION|LIFE) E3501/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Medion\MdLifeE3501($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/MEDION E4502/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Medion\MdE4502($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/MEDION E4504/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Medion\MedionE4504($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/MEDION E4506/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Medion\MedionE4506($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/MEDION E4503/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Medion\MedionE4503($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/MEDION E4005/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Medion\MedionE4005($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/MEDION X5004/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Medion\MdX5004($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/MEDION X5020/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Medion\MdX5020($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/MEDION X4701/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Medion\MdX4701($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/MEDION P5001/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Medion\MedionP5001($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/MEDION P5004/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Medion\MedionP5004($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/MEDION P5005/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Medion\MedionP5005($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/MEDION S5004/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Medion\MedionS5004($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/YUANDA50/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Yuanda\Yuanda50($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/IQ4415/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Fly\FlyIq4415($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/IQ4490/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Fly\FlyIq4490($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/IQ449/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Fly\FlyIq449($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/IQ448/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Fly\FlyIq448($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/IQ444/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Fly\FlyIq444($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/IQ442/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Fly\FlyIq442($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/IQ436i/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Fly\FlyIq436i($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/IQ434/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Fly\FlyIq434($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/IQ452/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Fly\FlyIq452($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/IQ456/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Fly\FlyIq456($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/IQ4502/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Fly\FlyIq4502($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/IQ4504/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Fly\FlyIq4504($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/IQ450/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Fly\FlyIq450($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/(CX919|gxt_dongle_3188)/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Tv\AndoerCx919($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/PAP5000TDUO/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Prestigio\PrestigioPap5000tDuo($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/PAP5000DUO/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Prestigio\PrestigioPap5000Duo($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/PAP5044DUO/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Prestigio\PrestigioPap5044Duo($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/PAP7600DUO/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Prestigio\PrestigioPap7600Duo($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/PAP4500DUO/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Prestigio\PrestigioPap4500Duo($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/PAP4044DUO/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Prestigio\PrestigioPap4044Duo($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/PAP3350DUO/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Prestigio\PrestigioPap3350Duo($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/PAP5503/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Prestigio\PrestigioPap5503($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/PMT3037_3G/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Prestigio\PrestigioPmt30373g($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/PMP7074B3GRU/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Prestigio\PrestigioPmp7074b3gru($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/PMP3007C/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Prestigio\PrestigioPmp3007c($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/PMP3970B/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Prestigio\PrestigioPmp3970b($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/sprd\-B51\+/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Sprd\SprdB51plus($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/BlackBerry 9790/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\BlackBerry\BlackBerry9790($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/BlackBerry 9720/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\BlackBerry\BlackBerry9720($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/BB10; Kbd/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\BlackBerry\BlackBerryKbd($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/BB10; Touch/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\BlackBerry\BlackBerryZ10($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/XT1068/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Motorola\MotorolaXt1068($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/XT1039/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Motorola\MotorolaXt1039($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/XT1032/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Motorola\MotorolaXt1032($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/XT1080/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Motorola\MotorolaXt1080($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/XT1021/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Motorola\MotorolaXt1021($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/MotoG3/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Motorola\MotorolaMotoG3($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/MB612/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Motorola\MotorolaMb612($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/nexus 6p/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Huawei\HuaweiNexus6p($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/nexus 6/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Motorola\MotorolaNexus6($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/ME302KL/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Asus\AsusMe302kl($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/nexus[ _]?7/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Asus\AsusGalaxyNexus7($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/K013/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Asus\AsusMemoPadK013($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/K01E/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Asus\AsusFoneK01E($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Z00AD/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Asus\AsusZ00ad($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/K012/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Asus\AsusFoneK012($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/ME302C/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Asus\AsusMe302c($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/T00N/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Asus\AsusT00n($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/T00J/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Asus\AsusT00j($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/P01Y/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Asus\AsusP01y($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/PadFone T004/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Asus\AsusPadFoneT004($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/PadFone 2/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Asus\AsusPadFone2($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/PadFone/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Asus\AsusPadFone($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/TF300TG/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Asus\AsusTf300Tg($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/TF300TL/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Asus\AsusTf300Tl($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/TF300T/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Asus\AsusTf300T($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/P1801\-T/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Asus\AsusP1801t($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/WIN HD W510u/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Blu\BluWinHdW510u($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/STUDIO 5\.5/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Blu\BluStudio55($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/N9500/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Star\StarN9500($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/tolino tab 8\.9/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Tolino\TolinoTab89($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/tolino tab 8/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Tolino\TolinoTab8($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Lenovo S660/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lenovo\LenovoS660($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Lenovo S920/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lenovo\LenovoS920($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Lenovo S720/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lenovo\LenovoS720($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/IdeaTab S6000\-H/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lenovo\LenovoS6000hIdeaTab($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/IdeaTabS2110AH/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lenovo\LenovoS2110ahIdeaTab($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/IdeaTabS2110AF/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lenovo\LenovoS2110afIdeaTab($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/IdeaTabS2109A\-F/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lenovo\LenovoS2109afIdeaTab($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Lenovo A606/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lenovo\LenovoA606($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Lenovo A850\+/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lenovo\LenovoA850Plus($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Lenovo A766/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lenovo\LenovoA766($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Lenovo A536/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lenovo\LenovoA536($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SmartTabII10/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lenovo\VodafoneSmartTabIi10($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Vodafone Smart Tab III 10/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lenovo\VodafoneSmartTabIii10($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Smart Tab 4G/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lenovo\VodafoneSmartTab4g($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Smart Tab 4/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lenovo\VodafoneSmartTab4($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Smart Tab 3G/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lenovo\VodafoneSmartTab3g($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/P1032X/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lenovo\LenovoP1032x($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/P1050X/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lenovo\LenovoP1050x($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Lenovo A7000\-a/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lenovo\LenovoA7000a($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/LenovoA3300\-GV/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lenovo\LenovoA3300gv($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Lenovo B6000\-HV/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lenovo\LenovoB6000hv($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Lenovo B6000\-H/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lenovo\LenovoB6000h($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Lenovo B6000\-F/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lenovo\LenovoB6000f($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Lenovo B8080\-H/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lenovo\LenovoB8080h($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Lenovo TB2\-X30F/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lenovo\LenovoTb2x30f($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Lenovo YT3\-X50L/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lenovo\LenovoYt3x50l($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Lenovo K900/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lenovo\LenovoK900($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/AT1010\-T/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lenovo\LenovoAt1010t($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/A10\-70F/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lenovo\LenovoA1070f($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Lenovo A1000L\-F/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lenovo\LenovoA1000lf($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Lenovo A1000\-F/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lenovo\LenovoA1000f($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Lenovo A1000/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lenovo\LenovoA1000($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/YOGA Tablet 2 Pro\-1380L/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lenovo\Lenovo1380L($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/YOGA Tablet 2 Pro\-1380F/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lenovo\Lenovo1380F($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/YOGA Tablet 2\-1050L/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lenovo\Lenovo1050L($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/YOGA Tablet 2\-1050F/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lenovo\Lenovo1050F($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/YOGA Tablet 2\-830L/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lenovo\Lenovo830L($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/YOGA Tablet 2\-830F/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Lenovo\Lenovo830F($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/S208/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Cubot\CubotS208($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/306SH/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Sharp\SH306($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/JERRY/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Wiko\WikoJerry($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/BLOOM/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Wiko\WikoBloom($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/DARKSIDE/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Wiko\WikoDarkside($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SLIDE2/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Wiko\WikoSlide2($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/ M3 /', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Gionee\GioneeMarathonM3($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/4034D/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Alcatel\AlcatelOt4034D($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/4030D/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Alcatel\AlcatelOt4030D($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/4030X/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Alcatel\AlcatelOt4030X($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/4012X/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Alcatel\AlcatelOt4012X($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/4012A/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Alcatel\AlcatelOt4012A($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/4015D/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Alcatel\AlcatelOt4015D($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/4015X/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Alcatel\AlcatelOt4015X($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/7041D/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Alcatel\AlcatelOt7041d($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/7041X/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Alcatel\AlcatelOt7041x($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/7047D/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Alcatel\AlcatelOt7047d($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/7049D/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Alcatel\AlcatelOt7049d($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/7025D/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Alcatel\AlcatelOt7025d($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/5020D/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Alcatel\AlcatelOt5020D($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/5035D/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Alcatel\AlcatelOt5035D($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/5036D/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Alcatel\AlcatelOt5036D($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/4037T/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Alcatel\AlcatelOt4037T($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/P310X/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Alcatel\AlcatelP310x($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/P310A/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Alcatel\AlcatelP310a($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/P320X/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Alcatel\AlcatelP320x($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/6040D/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Alcatel\AlcatelOt6040D($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/6043D/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Alcatel\AlcatelOt6043D($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/6010D/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Alcatel\AlcatelOt6010D($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/6010X/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Alcatel\AlcatelOt6010X($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/6015X/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Alcatel\AlcatelOt6015X($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/6035R/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Alcatel\AlcatelOt6035R($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/6034R/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Alcatel\AlcatelOt6034R($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/6032/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Alcatel\AlcatelOt6032($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/6036Y/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Alcatel\AlcatelOt6036Y($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/6033X/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Alcatel\AlcatelOt6033X($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/6030X/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Alcatel\AlcatelOt6030X($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/6030D/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Alcatel\AlcatelOt6030D($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/6050A/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Alcatel\AlcatelOt6050A($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/8008D/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Alcatel\AlcatelOt8008D($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/8000D/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Alcatel\AlcatelOt8000D($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/5042D/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Alcatel\AlcatelOt5042D($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/one[ _]touch[ _]995/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Alcatel\AlcatelOt995($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/one[ _]touch[ _]997d/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Alcatel\AlcatelOt997d($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/one[ _]touch[ _]992d/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Alcatel\AlcatelOt992d($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/one[ _]touch[ _]991t/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Alcatel\AlcatelOt991t($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/one[ _]touch[ _]991d/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Alcatel\AlcatelOt991d($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/one[ _]touch[ _]991/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Alcatel\AlcatelOt991($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/one[ _]touch[ _]903D/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Alcatel\AlcatelOt903d($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/one[ _]touch[ _]980/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Alcatel\AlcatelOt980($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/one[ _]touch[ _]985d/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Alcatel\AlcatelOt985d($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/one[ _]touch[ _]818/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Alcatel\AlcatelOt818($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/one[ _]touch[ _]tab[ _]7hd/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Alcatel\AlcatelOtTab7hd($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/one[ _]touch[ _]tab[ _]8hd/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Alcatel\AlcatelOtTab8hd($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/one[ _]touch[ _]p321/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Alcatel\AlcatelP321($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/one[ _]touch[ _]Fierce/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Alcatel\AlcatelFierce($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Archos 50b Platinum/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Archos\Archos50bPlatinum($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Archos 50 Platinum/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Archos\Archos50Platinum($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Archos 50 Titanium/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Archos\Archos50Titanium($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Archos 50 Oxygen Plus/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Archos\Archos50OxygenPlus($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/ARCHOS 101 XS 2/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Archos\Archos101xs2($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Archos 101d Neon/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Archos\Archos101dNeon($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Archos 121 Neon/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Archos\Archos121Neon($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Archos 101 Neon/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Archos\Archos101Neon($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Archos 101 Copper/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Archos\Archos101Copper($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/ZTE Blade V6/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Zte\ZteBladev6($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/ZTE Blade L5 Plus/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Zte\ZteBladeL5plus($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/ZTE Blade L6/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Zte\ZteBladeL6($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/ZTE Blade L2/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Zte\ZteBladeL2($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/ZTE Blade L3/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Zte\ZteBladeL3($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/ZTE N919/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Zte\ZteN919($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Beeline Pro/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Zte\ZteBeelinePro($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SmartTab7/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Zte\ZteSmartTab7($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/ZTE_V829/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Zte\ZteV829($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/ZTE Geek/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Zte\ZteV975($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/ZTE LEO Q2/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Zte\ZteLeoQ2($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/IEOS_QUAD_10_PRO/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Odys\OdysIeosQuad10pro($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/IEOS_QUAD_W/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Odys\OdysIeosQuadw($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/MAVEN_10_PLUS/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Odys\OdysMaven10plus($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/CONNECT7PRO/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Odys\OdysConnect7pro($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SPACE10_PLUS_3G/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Odys\OdysSpace10plus3g($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/AT300SE/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Toshiba\ToshibaAt300SE($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/A3\-A11/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Acer\AcerIconiaA3A11($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/A3\-A10/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Acer\AcerIconiaA3A10($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/A700/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Acer\AcerIconiaA700($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/B1\-711/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Acer\AcerIconiaB1711($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/B1\-770/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Acer\AcerIconiaB1770($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/MediPaD13/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Bewatec\BewatecMediPad13($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/MediPaD/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Bewatec\BewatecMediPad($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/TechniPad[_ ]10\-3G/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Technisat\TechnisatTechnipad103g($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/TechniPad[_ ]10/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Technisat\TechnisatTechnipad10($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/AQIPAD[_ ]7G/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Technisat\TechnisatAqistonAqipad7g($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/TechniPhone[_ ]5/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Technisat\TechnisatTechniphone5($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/M7T/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Pipo\PipoM7t3g($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/M83g/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Pipo\PipoM83g($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/ M6 /', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Pipo\PipoM6($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/ORION7o/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\GoClever\GoCleverOrion7o($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/GOCLEVER TAB A93\.2/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\GoClever\GoCleverTabA932($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/QUANTUM 4/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\GoClever\GoCleverQuantum4($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/QUANTUM_700m/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\GoClever\GoCleverQuantum700m($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/NT\-1009T/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\IconBit\IconBitNt1009t($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/NT\-3702M/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\IconBit\IconBitNt3702m($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Philips W336/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Philips\PhilipsW336($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/KianoIntelect7/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Kiano\KianoIntelect7($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SUPRA_M121G/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Supra\SupraM121g($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/HW\-W718/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Haier\HaierW718($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Micromax A59/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Micromax\MicromaxA59($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/AX512/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Bmobile\BmobileAx512($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/AX540/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Bmobile\BmobileAx540($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/s4502m/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Dns\DnsS4502m($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/s4502/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Dns\DnsS4502($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/s4501m/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Dns\DnsS4501m($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/s4503q/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Dns\DnsS4503q($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/PULID F11/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Pulid\PulidF11($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/PULID F15/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Pulid\PulidF15($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/thl_4400/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Thl\Thl4400($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/thl 2015/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Thl\Thl2015($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/ThL W7/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Thl\ThlW7($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/ThL W8/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Thl\ThlW8($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/iDxD4/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Digma\DigmaIdxd4($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/PS1043MG/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Digma\DigmaPs1043mg($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/TT7026MW/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Digma\DigmaTt7026mw($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/i\-mobile IQX OKU/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Imobile\ImobileIqxoku($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/i\-mobile IQ 6A/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Imobile\ImobileIq6a($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/RMD\-757/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Ritmix\RitmixRmd757($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/RMD\-1040/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Ritmix\RitmixRmd1040($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/A400/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Celkon\CelkonA400($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/T108/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Twinovo\TwinovoT108($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/T118/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Twinovo\TwinovoT118($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/N\-06E/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Nec\NecN06e($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/OK999/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Sunup\SunupOk999($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/PICOpad_S1\(7_3G\)/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Axioo\AxiooPicopadS13g($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/ ACE /', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGts5830($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (!preg_match('/trident/i', $useragent) && preg_match('/Android/', $useragent) && preg_match(
        '/iphone[ ]?5c/i',
        $useragent
    )
    ) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Xianghe\XiangheIphone5c($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (!preg_match('/trident/i', $useragent) && preg_match('/Android/', $useragent) && preg_match(
        '/iphone[ ]?5/i',
        $useragent
    )
    ) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Xianghe\XiangheIphone5($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (!preg_match('/trident/i', $useragent) && preg_match('/Android/', $useragent) && preg_match(
        '/iphone[ ]?6c/i',
        $useragent
    )
    ) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Xianghe\XiangheIphone6c($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (!preg_match('/trident/i', $useragent) && preg_match('/Android/', $useragent) && preg_match(
        '/iphone/i',
        $useragent
    )
    ) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Xianghe\XiangheIphone($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/DG800/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Doogee\DoogeeDg800($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/DG330/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Doogee\DoogeeDg330($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/DG2014/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Doogee\DoogeeDg2014($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/F3_Pro/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Doogee\DoogeeF3pro($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/TAB785DUAL/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Sunstech\SunstechTab785dual($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Norma 2/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Keneksi\KeneksiNorma2($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Adi_5S/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Artel\ArtelAdi5s($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/BRAVIS NP 844/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Bravis\BravisNp844($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/fnac 4\.5/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Fnac\FnacPhablet45($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/T880G/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Etuline\EtulineT880g($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/TCL M2U/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Tcl\TclM2u($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/TCL S720T/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Tcl\TclS720t($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/radxa rock/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Radxa\RadxaRock($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/DM015K/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Kyocera\KyoceraDm015k($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/KC\-S701/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Kyocera\KyoceraKcs701($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/FP1U/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Fairphone\FairphoneFp1u($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/FP1/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Fairphone\FairphoneFp1($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/ImPAD 0413/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Impression\ImpressionImpad0413($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/ImPAD6213M_v2/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Impression\ImpressionImpad6213Mv2($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/KFASWI/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Amazon\AmazonKfaswi($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/SD4930UR/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Amazon\AmazonSd4930urFirePhone($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Art 3G/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Explay\ExplayArt3g($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/R815/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Oppo\OppoR815($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/TAB\-970/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Prology\PrologyTab970($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/IM\-A900K/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Pantech\PantechIma900k($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Pacific 800/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Oysters\OystersPacific800($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Pacific800i/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Oysters\OystersPacific800i($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/TM\-7055HD/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Texet\TexetTm7055hd($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/TM\-5204/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Texet\TexetTm5204($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/AP\-804/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Assistant\AssistantAp804($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Atlantis 1010A/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Blaupunkt\BlaupunktAtlantis1010a($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/AC0732C/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\TriQ\TriQAc0732c($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/TBD1083/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Zeki\ZekiTbd1083($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/TBDC1093/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Zeki\ZekiTbdc1093($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/A66A/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Evercoss\EvercossA66a($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/IP1020/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Dex\DexIp1020($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Turbo Pad 500/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\TurboPad\TurboPadPad500($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Turbo X6/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\TurboPad\TurboPadTurboX6($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Novo7Fire/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Ainol\AinolNovo7Fire($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/numy_note_9/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Ainol\AinolNumyNote9($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/TX08/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Irbis\IrbisTx08($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/TX18/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Irbis\IrbisTx18($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/U25GT\-C4W/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Cube\CubeU25gtc4w($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/iPad/', $useragent) && !preg_match('/tripadvisor/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Apple\Ipad($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/iPod/', $useragent) && !preg_match('/iPodder/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Apple\Ipod($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (!preg_match('/trident/i', $useragent) && preg_match('/iPhone/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Apple\Iphone($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Puffin\/[\d\.]+IT/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Apple\Ipad($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Puffin\/[\d\.]+IP/', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Apple\Iphone($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/Galaxy Nexus/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGalaxyNexus($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/galaxy tab 2 3g/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGtp3100($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/galaxy tab 2/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGtp3110($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/galaxy j5/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungSmJ500($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/OMNIA7/i', $useragent)) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Samsung\SamsungGti8700Omnia7($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/one/i', $useragent)
        && !preg_match('/PodcastOne/i', $useragent)
        && !preg_match('/iOS/', $useragent)
    ) {
        $device = new \BrowserDetector\Detector\Device\Mobile\Htc\HtcOne($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } elseif (preg_match('/CFNetwork/', $useragent)) {
        $device = \BrowserDetector\Detector\Factory\Device\DarwinFactory::detect($useragent);

        $deviceBrand       = $device->getBrand();
        $deviceCode        = $device->getDeviceName();
        $devicePointing    = $device->getPointingMethod();
        $deviceType        = $device->getType()->getName();
        $deviceMaker       = $device->getManufacturer();
        $deviceName        = $device->getMarketingName();
        $deviceOrientation = $device->getDualOrientation();
    } else {
        /** @var \UaResult\Result\Result $result */
        $result = $detector->getBrowser($useragent);

        $device = $result->getDevice();

        if ($deviceCode === $device->getDeviceName()) {
            $deviceBrand       = $device->getBrand();
            $devicePointing    = $device->getPointingMethod();
            $deviceType        = $device->getType()->getName();
            $deviceMaker       = $device->getManufacturer();
            $deviceName        = $device->getMarketingName();
            $deviceOrientation = $device->getDualOrientation();
        } elseif ('general Mobile Device' === $device->getDeviceName() && in_array(
            $deviceCode,
            ['general Mobile Phone', 'general Tablet']
        )
        ) {
            $deviceBrand       = $device->getBrand();
            $deviceCode        = $device->getDeviceName();
            $devicePointing    = $device->getPointingMethod();
            $deviceType        = $device->getType()->getName();
            $deviceMaker       = $device->getManufacturer();
            $deviceName        = $device->getMarketingName();
            $deviceOrientation = $device->getDualOrientation();
        } elseif ('Windows RT Tablet' === $device->getDeviceName() && $deviceCode === 'general Tablet') {
            $deviceBrand       = $device->getBrand();
            $deviceCode        = $device->getDeviceName();
            $devicePointing    = $device->getPointingMethod();
            $deviceType        = $device->getType()->getName();
            $deviceMaker       = $device->getManufacturer();
            $deviceName        = $device->getMarketingName();
            $deviceOrientation = $device->getDualOrientation();
        } elseif ('Windows Desktop' === $device->getDeviceName()
            && $deviceCode === 'unknown'
            && in_array(
                $platform->getMarketingName(),
                ['Windows 7', 'Windows 8', 'Windows 8.1', 'Windows 10', 'Windows XP', 'Windows Vista']
            )
        ) {
            $deviceBrand       = $device->getBrand();
            $deviceCode        = $device->getDeviceName();
            $devicePointing    = $device->getPointingMethod();
            $deviceType        = $device->getType()->getName();
            $deviceMaker       = $device->getManufacturer();
            $deviceName        = $device->getMarketingName();
            $deviceOrientation = $device->getDualOrientation();
        } elseif ('Linux Desktop' === $device->getDeviceName()
            && $deviceCode === 'unknown'
            && in_array($platform->getMarketingName(), ['Linux'])
        ) {
            $deviceBrand       = $device->getBrand();
            $deviceCode        = $device->getDeviceName();
            $devicePointing    = $device->getPointingMethod();
            $deviceType        = $device->getType()->getName();
            $deviceMaker       = $device->getManufacturer();
            $deviceName        = $device->getMarketingName();
            $deviceOrientation = $device->getDualOrientation();
        } elseif ('Macintosh' === $device->getDeviceName()
            && $deviceCode === 'unknown'
            && in_array($platform->getMarketingName(), ['Mac OS X', 'macOS'])
        ) {
            $deviceBrand       = $device->getBrand();
            $deviceCode        = $device->getDeviceName();
            $devicePointing    = $device->getPointingMethod();
            $deviceType        = $device->getType()->getName();
            $deviceMaker       = $device->getManufacturer();
            $deviceName        = $device->getMarketingName();
            $deviceOrientation = $device->getDualOrientation();
        }
    }

    return [
        $deviceBrand,
        $deviceCode,
        $devicePointing,
        $deviceType,
        $deviceMaker,
        $deviceName,
        $deviceOrientation,
        $device,
    ];
}
