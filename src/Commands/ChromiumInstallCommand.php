<?php

namespace Theodson\ChromiumInstall\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Based on work for the chromedriver in Laravel/Dusk and Staudenmeir.
 *
 * @copyright Inspired by Jonas Staudenmeir: https://github.com/staudenmeir/dusk-updater
 */
class ChromiumInstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dusk:chromium-install {version?}
                    {--basepath= : The directory to install Chromium}
                    {--link= : The Symbolic link referring to the installed version}
                    {--tidyup : Remove the downloaded archive after installation}
                    {--redownload : Overwrite any previously downloaded archives}
                    {--proxy= : The proxy to download the binary through (example: "tcp://127.0.0.1:9000")}
                    {--ssl-no-verify : Bypass SSL certificate verification when installing through a proxy}
                    {--with-driver : Try installing the latest chromedriver using dusk:update or dusk:chrome-driver}
                    ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install the Chromium Browser binary';

    /**
     * URL to the latest stable release version. Is baseRevision
     */
    protected string $latestVersionUrl = 'https://storage.googleapis.com/chromium-browser-snapshots/%s/LAST_CHANGE';

    /**
     * URL to the Chromium download.
     */
    protected string $downloadUrl = 'https://storage.googleapis.com/chromium-browser-snapshots/%s/%s/%s';

    /**
     * URL to query for Chromium download.
     */
    protected string $versionUrl = 'https://www.googleapis.com/download/storage/v1/b/chromium-browser-snapshots/o/%s%%2F%d%%2F%s?alt=media';

    /**
     * URL to resolve Chromium Version to basePosition : arg format is Major.Minor.Branch.Patch
     */
    // protected string $versionToPositionUrl = 'https://omahaproxy.appspot.com/deps.json?version=%s';
    // protected string $versionToPositionUrl = 'https://versionhistory.googleapis.com/v1/chrome/platforms/all/channels/all/versions/all/releases';
    protected string $versionToPositionUrl = 'https://chromiumdash.appspot.com/fetch_releases?channel=%s&platform=%s&num=800&offset=0';

    /**
     * Latest chromedriver url : arg format is Major.Minor.Branch.Patch
     */
    // protected $chromedriverVersionUrl = 'https://chromedriver.storage.googleapis.com/LATEST_RELEASE_%s';
    protected $chromedriverVersionUrl = 'https://googlechromelabs.github.io/chrome-for-testing/known-good-versions-with-downloads.json';


    protected bool $resolveVersionsAgainstChromeDriver = false;

    /**
     * Paths to executable from the extracted archive.
     *
     * @var array
     */
    protected $executables = [
        'linux' => 'chrome-linux/chrome',
        'mac' => 'chrome-mac/Chromium.app/Contents/MacOS/Chromium',
        'mac-intel' => 'chrome-mac/Chromium.app/Contents/MacOS/Chromium',
        'mac-arm' => 'chrome-mac/Chromium.app/Contents/MacOS/Chromium',
        'win' => '',
    ];

    protected $binarylinks = [
        'linux' => '/usr/local/bin/chromium',
        'mac' => '/usr/local/bin/chromium',
        'mac-intel' => '/usr/local/bin/chromium',
        'mac-arm' => '/usr/local/bin/chromium',
        'win' => '',
    ];

    protected $platforms = [
        'linux' => 'Linux_x64',
        'mac' => 'Mac',
        'mac-intel' => 'Mac',
        'mac-arm' => 'Mac_Arm',
    ];

    protected $archives = [
        'linux' => 'chrome-linux.zip',
        'mac' => 'chrome-mac.zip',
        'mac-intel' => 'chrome-mac.zip',
        'mac-arm' => 'chrome-mac.zip',
    ];

    private $minBrowserVersion = 78;

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $currentOS = $this->os();

        foreach ($this->platforms as $os => $platform) {
            if ($os === $currentOS) {
                //
                // Determine and verify Version/Revision
                //
                $version = $this->version($os);
                $majorVersion = (int)$this->majorVersion($version);

                if ($majorVersion < $this->minBrowserVersion || $majorVersion == 82) {
                    $this->warn("Version $majorVersion not supported. Minimum Version is $this->minBrowserVersion.");
                    // https://chromedriver.storage.googleapis.com/LATEST_RELEASE_82 fails for some reason!
                    exit(1);
                }
                // From this point on $version is in Major.Minor.Branch.Patch format.

                //
                // Determine Position (Cr-Commit-Position) as used in download URL discovery
                //
                $this->info(
                    "====== Resolve $version to basePositon ======",
                    OutputInterface::VERBOSITY_VERY_VERBOSE
                );

                $basePosition = $this->resolveToBasePosition($version, $platform);
                if (!$basePosition) {
                    $this->warn("Unable to resolve basePosition($basePosition) for major($majorVersion) in os $os. Try a lower number.");
                    exit(1);
                }

                $basePosition = $this->position($os, $basePosition);

                if (!$basePosition) {
                    $this->warn('Unable to find a position');
                    exit(1);
                }

                $binary = $this->download($os, $basePosition, $majorVersion);

                $this->info("Version $version resolved to basePosition $basePosition",
                    OutputInterface::VERBOSITY_VERY_VERBOSE
                );

                $this->info(sprintf('Chromium basePosition %s successfully installed at %s',
                        $majorVersion ? "$majorVersion ($basePosition)" : $basePosition, $binary)
                );

                $linkresult = $this->symlink($binary, $os, $basePosition, $majorVersion);

                //
                // Shall we update/install a ChromeDriver to match Chromium version
                //
                if ($this->option('with-driver')) {
                    $this->info('('.$majorVersion.') Inspecting Chromium binary : '.$binary,
                        OutputInterface::VERBOSITY_VERBOSE);
                    $this->info(system('[ -d vendor/laravel/dusk/bin/ ] && /bin/rm -f vendor/laravel/dusk/bin/*'));

                    if (config('chromium-install.prefer_laravel_dusk_chrome_driver')) {
                        // # how to pass single argument as named var doesn't work.
                        // $this->call('dusk:chrome-driver',['version' => $majorVersion]);
                        $this->info(system('php artisan dusk:chrome-driver '.$majorVersion));
                    } else {
                        $this->call('dusk:update', ['--detect' => $binary]);
                    }
                }
            }
        }

        return self::SUCCESS;
    }

    /**
     * Get version in Major.Minor.Branch.Patch format
     *
     * @return string|null in format Major.Minor.Branch.Patch version
     */
    protected function version($os): ?string
    {
        $version = $this->formatVersion($this->argument('version'));

        if (empty($version)) {
            //
            // we will use the latest chromedriver compatible version ( although chromium version are often ahead).
            //
            $this->info(sprintf('Cannot find version, using latest lookup (via chromedriver)'),
                OutputInterface::VERBOSITY_VERBOSE
            );
            if ($this->resolveVersionsAgainstChromeDriver) {
                $version = $this->chromedriverVersion($os);
            } else {
                $version = $this->chromiumVersion($os);
            }
        }

        if (!$this->iValidMultiPartVersion($version) && !empty($version)) {
            //
            // Using major release numbers we can get mmbp version (Major.Minor.Branch.Patch)
            // basing this on the chromedriver versions to ensure compatability in Dusk tests.
            //
            $month = date('M');
            $cachedReleasesMetaPath = $this->getBasePath().DIRECTORY_SEPARATOR."known-good-versions-with-downloads.json-$month.json";
            if (file_exists($cachedReleasesMetaPath)) {
                $this->info(sprintf('Using Cached Versions Info from %s', $cachedReleasesMetaPath));

                $versions = json_decode(file_get_contents($cachedReleasesMetaPath),true);
            } else {
                $this->info(sprintf('Writing Cached Versions Info at %s', $cachedReleasesMetaPath));
                $versions = json_decode($this->getUrl($this->chromedriverVersionUrl),false)['versions'];
                file_put_contents($cachedReleasesMetaPath, json_encode($versions));
            }

            $mostRecent = collect( $versions ?? [])
                ->filter(function ($cdv) use ($version) {
                    return explode('.', $cdv['version'])[0] == explode('.', $version)[0]; }) // match major.
                ->sortByDesc('revision')
                ->first(); // get the most recent mmbp version for major
            $version = $mostRecent['version'] ?? $version;
        }

        return $this->formatVersion($version) ?: null;
    }

    /**
     * Get the desired Chromium version.
     *
     * @param $platform
     * @param $basePosition int position to start download attempts
     * @return array|bool|mixed|string
     */
    protected function position($os, $basePosition = null)
    {
        //
        // We receive chromium zip archives identified by position
        //
        if (!$basePosition) {
            $platform = $this->platforms[$os];
            $basePosition = $this->latestPosition($platform);
            $this->info("Retrieving latest basePosition = $basePosition");
        }

        return $this->nearestPosition($os, $basePosition);
    }

    /**
     * Get the latest stable Chromium version.
     *
     * @return string value is basePosition
     */
    protected function latestPosition($platform): bool|string
    {
        try {
            return trim($this->getUrl(sprintf($this->latestVersionUrl, $platform)));
        } catch (\Exception $e) {
            return false;
        }
    }

    private function nearestPosition($os, int|string $basePosition): string|int|null
    {
        /*
         * Find a Chromium we can download.
         *
         * Attempt to download header info for $basePosition URL...
         *   if the URL fails increment the basePosition number and try again..
         *   do this for a set number of retries.
         */
        $retries = 0;

        while ($retries++ < 25) {
            $headers = @get_headers($url = $this->getVersionUrl($os, $basePosition));
            $this->info("Trying $basePosition at $url", OutputInterface::VERBOSITY_VERY_VERBOSE);

            if (!preg_grep('/HTTP.*404/', $headers)) {
                return $basePosition;
            }
            $basePosition++;
        }

        return null;
    }

    /**
     * @param  int  $release
     * @param  string  $platform
     * @param $channel
     * @return \Illuminate\Support\Collection
     */
    protected function getChromeMainBranchPosition(int $release, string $platform, $channel = "Stable")
    {
        $month = date('M');
        $cachedReleasesMetaPath = $this->getBasePath().DIRECTORY_SEPARATOR."$platform-$channel-$month-chromium-releases.json";
        if (file_exists($cachedReleasesMetaPath)) {
            $this->info(sprintf('Using Cached Release Meta from %s', $cachedReleasesMetaPath));

            $positions = collect(json_decode(file_get_contents($cachedReleasesMetaPath), true));
        } else {
            $this->info(sprintf('Writing Cached Release Meta at %s', $cachedReleasesMetaPath));
            $positions = collect(\Http::get(sprintf($this->versionToPositionUrl, $channel, $platform))->json());
            file_put_contents($cachedReleasesMetaPath, $positions->toJson());
        }

        return $positions->filter(fn($rel) => $rel['milestone'] === $release) ?? collect();
    }

    /**
     * Resolve Major.Minor.Branch.Patch version into basePosition number
     *
     * @param  string  $version  must be Major.Minor.Branch.Patch
     * @return string|null returns the basePosition
     */
    protected function resolveToBasePosition(string $version, string $platform): string|null
    {
        try {
            $positions = $this->getChromeMainBranchPosition((int)$version, $platform);
            $basePosition = $positions->first()['chromium_main_branch_position'];

            $this->info("LATEST_RELEASE $version : basePosition $basePosition", OutputInterface::VERBOSITY_VERBOSE);

            return $basePosition;
        } catch (\Exception $e) {
            $this->error("Resolve Base Position Failed:".$e->getMessage(), OutputInterface::VERBOSITY_NORMAL);

            return null;
        }
    }

    /**
     * Download the Chromium archive.
     *
     * https://www.googleapis.com/download/storage/v1/b/chromium-browser-snapshots/o/Mac%2F870776%2Fchrome-mac.zip
     * https://www.googleapis.com/storage/v1/b/chromium-browser-snapshots/o?delimiter=/
     *
     * @param  string  $version
     * @return string path to binary
     */
    protected function download($os, $version, $majorVersion): string
    {
        system(sprintf('mkdir -p %s %s &>/dev/null', config('chromium-install.downloads'),
            config('chromium-install.base_path')));
        $url = $this->getVersionUrl($os, $version);
        $archive = config('chromium-install.downloads').'/'.sprintf('chromium.%s.zip', $version);

        if ($this->option('redownload') || !file_exists($archive)) {
            $this->info("Downloading $url to $archive");
            file_put_contents($archive, $this->getUrl($url));
        }

        $binary = realpath(sprintf('%s/%s', $this->extract($archive, $majorVersion), $this->executables[$os]));
        $this->info('Path to binary:'.$binary, OutputInterface::VERBOSITY_DEBUG);

        return $binary;
    }

    /**
     * Extract the Chromium binary from the archive and optionally delete the archive.
     *
     * @param  string  $archive  path to archive
     */
    protected function extract($archive, $version): string
    {
        $path = $this->extractionPath($version, $this->getBasePath());

        $this->info("Path [$path]", OutputInterface::VERBOSITY_DEBUG);
        $this->info("Archive [$archive]", OutputInterface::VERBOSITY_DEBUG);
        system("type -t unzip &>/dev/null && unzip -ou $archive -d $path &>/dev/null");

        if ($this->option('tidyup')) {
            $this->info("Removing Archive $archive");
            unlink($archive);
        }

        return (string)$path;
    }

    /**
     * Get the contents of a URL using the 'proxy' and 'ssl-no-verify' command options.
     *
     * @return string|bool
     */
    protected function getUrl(string $url)
    {
        $contextOptions = [];

        if ($this->option('proxy')) {
            $contextOptions['http'] = ['proxy' => $this->option('proxy'), 'request_fulluri' => true];
        }

        if ($this->option('ssl-no-verify')) {
            $contextOptions['ssl'] = ['verify_peer' => false];
        }

        $streamContext = stream_context_create($contextOptions);

        return file_get_contents($url, false, $streamContext);
    }

    public function getDownloadUrl($os, string $version): string
    {
        return sprintf($this->downloadUrl, $this->platforms[$os], $version, $this->archives[$os]);
    }

    public function getVersionUrl($os, string $version): string
    {
        return sprintf($this->versionUrl, $this->platforms[$os], $version, $this->archives[$os]);
    }

    /**
     * Defaults to <HOME/chromium/majorversion> in user's home directory.
     *
     * @param  null  $base
     */
    public function extractionPath(mixed $version, $base = null): string
    {
        return sprintf('%s/%s',
            $base ? str_replace('$HOME', getenv('HOME'), $base) : sprintf('%s/chromium', getenv('HOME')),
            $version);
    }

    public function symlink(string $binary, mixed $os, mixed $version, $majorVersion = null): string|false
    {
        @chmod($binary, 0755);
        $link = $this->option('link') ?: $this->binarylinks[$os];

        $this->info(
            sprintf('linking %s %s', $binary, $link),
            OutputInterface::VERBOSITY_VERY_VERBOSE
        );

        if ($os === 'linux') {
            system("ln -snf $binary $link", $result);

            if ($result === 0) {
                $this->info(sprintf('Chromium linked to %s', $link));

                return true;
            }
        }

        $linkAsPosition = false; // nope - do we really need to link major version to position ( e.g. 1084013 -> 110 )
        if ($linkAsPosition) {
            $path = $this->extractionPath($version, $this->getBasePath());
            $majorPath = $this->extractionPath(empty($majorVersion) ? 'latest' : $majorVersion, $this->getBasePath());
            system("ln -snf $majorPath $path", $result);
            if ($result === 0) {
                $this->info(sprintf('Chromium %s linked to %s', $majorPath, $path));
            }
        }

        return false;
    }

    /**
     * Detect the current operating system.
     *
     * @return string
     */
    protected function os()
    {
        if (PHP_OS === 'WINNT' || Str::contains(php_uname(), 'Microsoft')) {
            return 'win';
        }

        if (PHP_OS === 'Darwin') {
            return match (php_uname('m')) {
                'arm64' => 'mac-arm',
                'x86_64' => 'mac-intel',
                default => 'mac',
            };
        }

        return 'linux';
    }

    /*
     * Validates and aligns format to Major.Minor.Branch.Patch or Major
     * Input and return
     *   110.2.3.12.34.1223.34 returns 110.2.3.12
     *              110.2.3.12 returns 110.2.3.12
     *                 110.2.3 returns 110
     *                     110 returns 110
     */
    protected function formatVersion($version): string
    {
        $parts = explode('.', $version);
        $version = $parts[0];
        if (count($parts) >= 4) {
            $version = implode('.', array_splice($parts, 0, 4));
        }

        return $version;
    }

    /**
     * Get the major version.
     */
    protected function majorVersion($version): string
    {
        $parts = explode('.', $version);

        return $parts[0];
    }

    protected function iValidMultiPartVersion($version): string
    {
        return $this->majorVersion($version) != $this->formatVersion($version);
    }

    /**
     * Get Chromedriver Major.Minor.Branch.Patch version given Major version
     *
     * @param  string|null  $version  major version or when null the Latest is returned.
     * @return string in format Major.Minor.Branch.Patch version
     */
    private function chromiumVersion($os, string $version = null): string
    {
        $slugs = [
            'linux' => 'linux',
            'mac-arm' => 'mac-arm-not-listed',
            'mac-intel' => 'mac',
            'win' => 'win',
        ];
        $os = $slugs[$os];

        $pattern = '/^'.$version.'\..*/';
        if (empty($version)) {
            // latest releases start with three digit.
            $pattern = '/^[0-9]{3}\..*/';
        }

        $versions = collect(json_decode($this->getUrl('https://chromium-downloads.herokuapp.com/builds'), true))
            ->filter(fn($e) => $e['os'] == $os && $e['channel'] === 'stable')
            ->map(fn($e) => $e['version'])
            ->values()
            ->filter(fn($e) => preg_grep($pattern, [$e]))
            ->sort();

        $this->info(print_r([$versions, $pattern], true), OutputInterface::VERBOSITY_DEBUG);

        return $versions->last();
    }

    /**
     * Get Chromedriver Major.Minor.Branch.Patch version given Major version
     *
     * @param  string|null  $version  major version or when null the Latest is returned.
     * @return string in format Major.Minor.Branch.Patch version
     */
    private function chromedriverVersion($os, string $version = null): string
    {
        $slugs = [
            'linux' => 'linux64',
            'mac-arm' => 'mac64_m1',
            'mac-intel' => 'mac64',
            'win' => 'win32',
        ];

        // lookup os string for pattern matching
        $os = $slugs[$os] ?? '.';
        $pattern = '/^'.$version.'.*'.$os.'\.zip$/';
        if (empty($version)) {
            $pattern = '/^[0-9]{3}.*'.$os.'\.zip$/'; // latest releases start with three digit.
        }

        $raw = $this->getUrl('https://chromedriver.storage.googleapis.com/');
        $xml = simplexml_load_string($raw);

        $latest = collect(json_decode(json_encode($xml), true)['Contents'])
            ->map(fn($e) => $e['Key'])
            ->values()
            ->filter(fn($e) => preg_grep($pattern, [$e]))
            ->sort();

        $this->info(print_r([$latest, $pattern], true), OutputInterface::VERBOSITY_DEBUG);

        // Key is in format 97.0.4692.71/chromedriver_mac64.zip
        return $this->formatVersion(explode('/', $latest->last())[0]);
    }

    public function getBasePath(): string|array|bool|null
    {
        return empty($this->option('basepath')) ? config('chromium-install.base_path') : $this->option('basepath');
    }

    /**
     * Notes: Chromium and ChromeDriver
     *
     * Chromium - https://www.chromium.org/getting-involved/download-chromium/
     *
     * https://omahaproxy.appspot.com/deps.json?version=110.0.5481.177 JSON
     *
     * List of Platform and Versions https://commondatastorage.googleapis.com/chromium-browser-snapshots/index.html
     *   https://commondatastorage.googleapis.com/chromium-browser-snapshots/index.html?prefix=Mac/938557/
     *   https://commondatastorage.googleapis.com/chromium-browser-snapshots/index.html?prefix=Mac/1084008/
     *
     * Download Binary https://www.googleapis.com/download/storage/v1/b/chromium-browser-snapshots/o/Mac%2F938557%2Fchrome-mac.zip&alt=media
     *
     * Most Recent Stable for your Version ( only recent history - last 2 or 3 releases )
     *   $macVersions =  Http::get('https://omahaproxy.appspot.com/history.json')->collect()->filter(fn($e) => $e['os'] == 'mac' && $e['channel'] == 'stable')
     *
     * More complete list of versions
     * {
     *  "version": "113.0.5627.0",
     *  "os": "win",
     *  "channel": "canary",
     *  "timestamp": "2023-03-02T21:29:02.054Z"
     *  },
     *  $macVersions =  Http::get('https://chromium-downloads.herokuapp.com/builds')->collect()->filter(fn($e) => $e['os'] == 'mac' && $e['channel'] == 'stable')
     *
     * ChromeDriver
     *      https://chromedriver.storage.googleapis.com/
     *
     * Workflow 1 - Version Specified
     *  1. Get Major.Minor.Branch.Patch version for ChromeDriver for OS
     *  2. Resolve to chromedriverVersion to basePosition
     *  3. Find Nearest downloadable position.
     *
     * Workflow 2 - No Version - Find Latest
     *  1. Find latest ChromeDriver version for OS
     *  Continue as Workflow 1
     */
}
