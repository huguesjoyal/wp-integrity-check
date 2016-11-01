<?php
namespace WPIntegrityCheck;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\DescriptorHelper;
use Symfony\Component\Console\Input\InputOption;

class IntegrityCheckCommand extends Command
{

    /**
     * Configure the wp-integrity-check command
     *
     * {@inheritdoc}
     *
     * @see \Symfony\Component\Console\Command\Command::configure()
     */
    protected function configure()
    {
        $this->setName('wp-integrity-check')
            ->setDescription('Check integrity of all WordPress installation in a specific path.')
            ->setDefinition(array(
            new InputArgument('path', InputArgument::OPTIONAL, 'The path of containing WordPress installation that would be scanned.'),
            new InputOption('depth', null, InputOption::VALUE_REQUIRED, 'The maximum depth to scan for WordPress installation')
        ));
    }

    /**
     * Execute the command
     *
     * {@inheritdoc}
     *
     * @see \Symfony\Component\Console\Command\Command::execute()
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        
        // Get the path arguments
        $path = $input->getArgument('path');
        
        // If there is not path, then display help
        if (! $path) {
            $helper = new DescriptorHelper();
            $helper->describe($output, $this);
            return false;
        }
        
        // Get the fix option
        $fix = $input->getOption('fix');
        $depth = $input->getOption('depth');
        
        // Look for wp-version.php in the path
        $output->writeln('<info>Searching for WordPress installation in the path: ' . realpath($path) . '</info>');
        $finder = new Finder();
        $finder->files()
            ->ignoreVCS(true)
            ->ignoreDotFiles(true)
            ->ignoreUnreadableDirs(true)
            ->path('/wp-includes\/version.php/');
        
        // Set maximum depth
        if ($depth) {
            $finder->depth($depth + 1);
        }
        
        $files = $finder->in($path);
        
        if ($files->count() > 0) {
            foreach ($files as $file) {
                
                // We have found a WordPress, so which version is it?
                $realPath = $file->getRealPath();
                $wpPath = dirname(dirname($realPath)) . DS;
                $content = $file->getContents();
                if (preg_match('/\$wp_version = \'(.*)\';/', $content, $matches)) {
                    $version = $matches[1];
                    $output->writeln('WordPress found in path "' . $wpPath . '" version ' . $version);
                    
                    $this->checkCoreIntegrity($wpPath, $version, $output);
                    $this->checkPluginsIntegrity($wpPath, $output);
                    
                    $output->writeln('');
                }
            }
        } else {
            $output->writeln('None found!');
        }
    }

    /**
     * Check WordPress integrity core
     * 
     * @param string $wpPath
     *            the WordPress installation path
     * @param string $version
     *            the WordPress installed version
     * @param OutputInterface $output            
     */
    private function checkCoreIntegrity($wpPath, $version, OutputInterface $output)
    {
        if ($this->ensureWPFilesExists($version)) {
            $output->write('<info>Checking core integrity...</info>');
            
            $problems = array();
            
            $wpZipFile = BASE_PATH . '/cache/wordpress-' . $version . '.zip';
            $zip = new \ZipArchive();
            if ($zip->open($wpZipFile) === true) {
                
                // Verify base files integrity check
                $finder = new Finder();
                $finder->files()
                    ->ignoreVCS(true)
                    ->ignoreDotFiles(true)
                    ->ignoreUnreadableDirs(true)
                    ->name('*.php');
                
                foreach ($finder->in($wpPath) as $file) {
                    
                    // Ignore wp-config
                    $filePath = $file->getRealPath();
                    $fileName = str_replace('\\', '/', str_replace($wpPath, '', $filePath));
                    
                    // Check base files integrity
                    if ($fileName != 'wp-config.php' && (! preg_match('/^wp-content\/themes\//', $fileName) || preg_match('/^wp-content\/themes\/(twentyfifteen|twentyfourteen|twentysixteen)/', $fileName)) && ! preg_match('/^wp-content\/plugins/', $fileName) && ! preg_match('/^wp-content\/mu-plugins\/Sept24/', $fileName)) {
                        
                        $md5sum = md5_file($filePath);
                        
                        $content = $zip->getFromName('wordpress/' . $fileName);
                        $md5Content = md5($content);
                        if (! $content && $md5sum != $md5Content) {
                            $problems[] = array(
                                $filePath,
                                'File not found in original archive.'
                            );
                        } elseif ($md5sum != $md5Content) {
                            $problems[] = array(
                                $filePath,
                                'MD5 is not the same as the original archive.'
                            );
                        }
                    }
                }
                
                $zip->close();
            }
            
            if (! empty($problems)) {
                $output->writeln('');
                $output->writeln("<error>Potentiels problems founds :</error>");
                
                $columns = array(
                    'File path',
                    'Problem'
                );
                
                $table = new Table($output);
                $table->setHeaders($columns)
                    ->setRows($problems)
                    ->render();
                $output->writeln('');
            } else {
                $output->writeln("OK");
            }
        } else {
            $output->writeln('<error>Cannot check integrity because the WordPress version could not be found!</error>');
        }
    }

    /**
     * Check WordPress plugins integrity
     * 
     * @param string $wpPath
     *            the WordPress installation path
     * @param OutputInterface $output            
     */
    private function checkPluginsIntegrity($wpPath, OutputInterface $output)
    {
        // Check plugins
        $pluginFinder = new Finder();
        $pluginFinder->files()
            ->ignoreVCS(true)
            ->ignoreDotFiles(true)
            ->ignoreUnreadableDirs(true)
            ->depth(0)
            ->directories();
        
        foreach ($pluginFinder->in($wpPath . '/wp-content/plugins/') as $plugin) {
            $pluginName = $plugin->getFilename();
            $pluginPath = $plugin->getRealPath();
            
            $version = $this->getPluginVersion($pluginPath);
            $output->write('Checking plugin - ' . $pluginName . ' ' . $version . '. ');
            
            if (! is_dir(BASE_PATH . '/cache/plugins')) {
                mkdir(BASE_PATH . '/cache/plugins');
            }
            
            $pluginDownloadPath = 'https://downloads.wordpress.org/plugin/' . $pluginName . '.' . $version . '.zip';
            $pluginZipFile = BASE_PATH . '/cache/plugins/' . $pluginName . '.' . $version . '.zip';
            
            // Download the plugin
            if (! file_exists($pluginZipFile)) {
                file_put_contents($pluginZipFile, file_get_contents($pluginDownloadPath));
            }
            
            if (filesize($pluginZipFile) > 0) {
                
                $problems = array();
                
                $zip = new \ZipArchive();
                if ($zip->open($pluginZipFile) === true) {
                    
                    // Verify base files integrity check
                    $finder = new Finder();
                    $finder->files()
                        ->ignoreVCS(true)
                        ->ignoreDotFiles(true)
                        ->ignoreUnreadableDirs(true)
                        ->name('*.php');
                    
                    foreach ($finder->in($pluginPath) as $file) {
                        
                        // Ignore wp-config
                        $filePath = $file->getRealPath();
                        $fileName = str_replace('\\', '/', str_replace($pluginPath, '', $filePath));
                        
                        // Check base files integrity
                        $md5sum = md5_file($filePath);
                        $content = $zip->getFromName($pluginName . $fileName);
                        $md5Content = md5($content);
                        if (! $content && $md5sum != $md5Content) {
                            $problems[] = array(
                                $filePath,
                                'File not found in original archive.'
                            );
                        } elseif ($md5sum != $md5Content) {
                            $problems[] = array(
                                $filePath,
                                'MD5 is not the same as the original archive.'
                            );
                        }
                    }
                    
                    $zip->close();
                }
                
                // Display plugins problems
                $output->writeln('');
                if (! empty($problems)) {
                    
                    $output->writeln("<error>Potentiels problems founds :</error>");
                    
                    $columns = array(
                        'File path',
                        'Problem'
                    );
                    
                    $table = new Table($output);
                    $table->setHeaders($columns)
                        ->setRows($problems)
                        ->render();
                    
                    $output->writeln('');
                }
            } else {
                $output->writeln('<error>Could not find plugin integrity package.</error>');
            }
        }
    }

    /**
     * Get the plugin version
     * 
     * @param string $pluginPath            
     * @return string
     */
    private function getPluginVersion($pluginPath)
    {
        $finder = new Finder();
        $finder->files()
            ->ignoreVCS(true)
            ->ignoreDotFiles(true)
            ->ignoreUnreadableDirs(true)
            ->depth(0)
            ->name('*.php');
        
        foreach ($finder->in($pluginPath) as $file) {
            if (preg_match('/^[ \t\/*#@]*' . preg_quote('Version', '/') . ':(.*)$/mi', $file->getContents(), $match) && $match[1]) {
                $trim = trim($match[1]);
                $array = explode(' ', $trim);
                $version = $array[0];
                return $version;
            }
        }
    }

    /**
     * Ensure that we have the WordPress installation files with a valid checksum
     *
     * @param string $version
     * @param string $try
     * @param string $maxTry
     * @return boolean
     */
    private function ensureWPFilesExists($version, $try = 1, $maxTry = 2)
    {
        if ($try > $maxTry) {
            return false;
        }
    
        $try ++;
    
        if (! is_dir(BASE_PATH . '/cache')) {
            mkdir(BASE_PATH . '/cache');
        }
    
        $wpZipFile = BASE_PATH . '/cache/wordpress-' . $version . '.zip';
        $wpZipMd5File = $wpZipFile . '.md5';
    
        if (! file_exists($wpZipFile)) {
            file_put_contents($wpZipFile, file_get_contents('https://' . $domain . '/wordpress-' . $version . '.zip'));
        }
    
        if (! file_exists($wpZipMd5File)) {
            file_put_contents($wpZipMd5File, file_get_contents('https://' . $domain . '/wordpress-' . $version . '.zip.md5'));
        }
    
        // Checksum
        $checksum = md5_file($wpZipFile);
        if ($checksum != file_get_contents($wpZipMd5File)) {
            @unlink($wpZipFile);
            @unlink($wpZipMd5File);
            return $this->ensureWPFilesExists($version, $try);
        }
    
        return true;
    }
    
    
}
