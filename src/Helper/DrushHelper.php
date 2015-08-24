<?php

namespace Platformsh\Cli\Helper;

use Platformsh\Cli\Local\LocalProject;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Project;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;

class DrushHelper extends Helper
{

    const AUTO_REMOVE_KEY = 'platformsh-cli-auto-remove';

    protected $homeDir = '~';

    /** @var OutputInterface */
    protected $output;

    /** @var ShellHelperInterface */
    protected $shellHelper;

    /** @var Filesystem */
    protected $fs;

    public function getName()
    {
        return 'drush';
    }

    /**
     * @param OutputInterface      $output
     * @param ShellHelperInterface $shellHelper
     * @param object               $fs
     */
    public function __construct(OutputInterface $output = null, ShellHelperInterface $shellHelper = null, $fs = null)
    {
        $this->output = $output ?: new NullOutput();
        $this->shellHelper = $shellHelper ?: new ShellHelper();
        $this->shellHelper->setOutput($this->output);
        $this->fs = $fs ?: new Filesystem();
    }

    /**
     * Get the installed Drush version.
     *
     * @return string
     *
     * @throws \Exception
     */
    public function getVersion()
    {
        static $version;
        if (isset($version)) {
            return $version;
        }
        exec($this->getDrushExecutable() . ' --version', $drushVersionOutput, $returnCode);
        if ($returnCode > 0) {
            $message = $returnCode == 127 ? 'Error finding Drush version' : 'Drush is not installed';
            throw new \Exception($message, $returnCode);
        }

        foreach ($drushVersionOutput as $line) {
          preg_match('/([0-9])([.0-9]*)/i', $line, $matches);
          $num = intval($matches[0]);
          if (is_numeric($num) && $num > 0) {
            return $num;
          }
        }

        return $version;
    }

    /**
     * @param string $minVersion
     * @param bool   $attemptInstall
     *
     * @throws \Exception
     */
    public function ensureInstalled($minVersion = '6', $attemptInstall = true)
    {
        try {
            $version = $this->getVersion();
        }
        catch (\Exception $e) {
            if ($e->getCode() === 127) {
                // Retry installing, if the default Drush does not exist.
                if ($attemptInstall && !getenv('PLATFORMSH_CLI_DRUSH') && $this->install()) {
                    $this->ensureInstalled($minVersion, false);

                    return;
                }
            }

            throw $e;
        }
        if (!$minVersion) {
            return;
        }
        if (version_compare($version, $minVersion, '<')) {
            throw new \Exception(
              sprintf('Drush version %s found, but %s (or later) is required', $version, $minVersion)
            );
        }
    }

    /**
     * Install Drush globally, using Composer.
     *
     * @param string $version The version to install. At the time of writing,
     *                        Platform.sh uses Drush 6.4.0.
     *
     * @return bool
     */
    protected function install($version = '6.4.0')
    {
        $args = array('composer', 'global', 'require', 'drush/drush:' . $version);

        return (bool) $this->shellHelper->execute($args, null, false, false);
    }

    /**
     * Set the user's home directory.
     *
     * @param string $homeDir
     */
    public function setHomeDir($homeDir)
    {
        $this->homeDir = $homeDir;
    }

    /**
     * Execute a Drush command.
     *
     * @param string[] $args
     *   Command arguments (everything after 'drush').
     * @param string   $dir
     *   The working directory.
     * @param bool     $mustRun
     *   Enable exceptions if the command fails.
     * @param bool     $quiet
     *   Suppress command output.
     *
     * @return string|bool
     */
    public function execute(array $args, $dir = null, $mustRun = false, $quiet = true)
    {
        array_unshift($args, $this->getDrushExecutable());

        return $this->shellHelper->execute($args, $dir, $mustRun, $quiet);
    }

    /**
     * Get the full path to the Drush executable.
     *
     * @return string
     */
    public function getDrushExecutable()
    {
        if (getenv('PLATFORMSH_CLI_DRUSH')) {
            return getenv('PLATFORMSH_CLI_DRUSH');
        }

        $executable = 'drush';
        if (strpos(PHP_OS, 'WIN') !== false && ($fullPath = shell_exec('where drush'))) {
            $executable = $fullPath;
        }

        return $executable;
    }

    /**
     * @return bool
     */
    public function clearCache()
    {
        return (bool) $this->execute(array('cache-clear', 'drush'));
    }

    /**
     * @param string $groupName
     *
     * @return string|bool
     */
    public function getAliases($groupName)
    {
        return $this->execute(
          array(
            'site-alias',
            '--pipe',
            '--format=list',
            '@' . $groupName,
          )
        );
    }

    /**
     * Create Drush aliases for the provided project and environments.
     *
     * @param Project       $project      The project
     * @param string        $projectRoot  The project root
     * @param Environment[] $environments The environments
     * @param string        $original     The original group name
     * @param bool          $merge        Whether to merge existing alias settings
     *
     * @throws \Exception
     *
     * @return bool Whether any aliases have been created.
     */
    public function createAliases(Project $project, $projectRoot, $environments, $original = null, $merge = true)
    {
        $config = LocalProject::getProjectConfig($projectRoot);
        $group = !empty($config['alias-group']) ? $config['alias-group'] : $project['id'];

        // Ensure the existence of the .drush directory.
        $drushDir = $this->homeDir . '/.drush';
        if (!is_dir($drushDir)) {
            mkdir($drushDir);
        }

        $filename = $drushDir . '/' . $group . '.aliases.drushrc.php';
        if (!is_writable($drushDir) || (file_exists($filename) && !is_writable($filename))) {
            throw new \Exception("Drush alias file not writable: $filename");
        }

        // Include the previous alias file(s) so that the user's own
        // modifications can be merged. This may create a PHP parse error for
        // invalid syntax, but in that case the user could not run Drush anyway.
        $aliases = array();
        $originalFiles = array($filename);
        if ($original) {
            array_unshift($originalFiles, $drushDir . '/' . $original . '.aliases.drushrc.php');
        }
        if ($merge) {
            foreach ($originalFiles as $originalFile) {
                if (file_exists($originalFile)) {
                    include $originalFile;
                }
            }
        }

        // Generate aliases for the remote environments.
        $numValidEnvironments = 0;
        $autoGenerated = '';
        foreach ($environments as $environment) {
            $newAlias = $this->generateRemoteAlias($environment);
            if (!$newAlias) {
                continue;
            }

            // If the alias already exists, recursively replace existing
            // settings with new ones.
            if (isset($aliases[$environment['id']])) {
                $newAlias = array_replace_recursive($aliases[$environment['id']], $newAlias);
                unset($aliases[$environment['id']]);
            }

            $autoGenerated .= "\n// Automatically generated alias for the environment \"" . $environment['title'] . "\".\n";
            $autoGenerated .= $this->exportAlias($environment['id'], $newAlias);
            $numValidEnvironments++;
        }

        // Generate an alias for the local environment.
        $localAliasName = '_local';
        $local = array(
          'root' => $projectRoot . '/' . LocalProject::WEB_ROOT,
          self::AUTO_REMOVE_KEY => true,
        );
        if (isset($aliases[$localAliasName])) {
            $local = array_replace_recursive($aliases[$localAliasName], $local);
            unset($aliases[$localAliasName]);
        }
        $localAlias = "\n// Automatically generated alias for the local environment.\n"
          . $this->exportAlias($localAliasName, $local);

        // Add any user-defined (pre-existing) aliases.
        $userDefined = '';
        foreach ($aliases as $name => $alias) {
            if (!empty($alias[self::AUTO_REMOVE_KEY])) {
                // This is probably for a deleted Platform.sh environment.
                continue;
            }
            $userDefined .= $this->exportAlias($name, $alias) . "\n";
        }
        if ($userDefined) {
            $userDefined = "\n// User-defined aliases.\n" . $userDefined;
        }

        $header = "<?php\n"
          . "/**\n * @file"
          . "\n * Drush aliases for the Platform.sh project \"{$project['title']}\"."
          . "\n *"
          . "\n * This file is auto-generated by the Platform.sh CLI."
          . "\n *"
          . "\n * WARNING"
          . "\n * This file may be regenerated at any time."
          . "\n * - User-defined aliases will be preserved."
          . "\n * - Aliases for active environments (including any custom additions) will be preserved."
          . "\n * - Aliases for deleted or inactive environments will be deleted."
          . "\n * - All other information will be deleted."
          . "\n */\n\n";

        $export = $header . $userDefined . $localAlias . $autoGenerated;

        $this->writeAliasFile($filename, $export);

        return true;
    }

    /**
     * Write a file and create a backup if the contents have changed.
     *
     * @param string $filename
     * @param string $contents
     */
    protected function writeAliasFile($filename, $contents)
    {
        if (is_readable($filename) && $contents !== file_get_contents($filename)) {
            $backupName = dirname($filename) . '/' . str_replace('.php', '.bak.php', basename($filename));
            $this->fs->rename($filename, $backupName, true);
        }
        $this->fs->dumpFile($filename, $contents);
    }

    /**
     * @param string $name
     * @param array  $alias
     *
     * @return string
     */
    protected function exportAlias($name, array $alias)
    {
        return "\$aliases['" . $name . "'] = " . var_export($alias, true) . ";\n";
    }

    /**
     * @param Environment $environment
     *
     * @return array|false
     */
    protected function generateRemoteAlias($environment)
    {
        if (!$environment->hasLink('ssh')) {
            return false;
        }
        $sshUrl = parse_url($environment->getLink('ssh'));
        if (!$sshUrl) {
            return false;
        }

        return array(
          'uri' => $environment->getLink('public-url'),
          'remote-host' => $sshUrl['host'],
          'remote-user' => $sshUrl['user'],
          // @todo can this be dynamic, based on an env var? see https://github.com/drush-ops/drush/issues/1370
          'root' => '/app/public',
          self::AUTO_REMOVE_KEY => true,
          'command-specific' => array(
              'site-install' => array(
                  'sites-subdir' => 'default',
              ),
          ),
        );
    }

}
