<?php

namespace fortrabbit\Copy\commands;

use Craft;
use craft\helpers\StringHelper;
use fortrabbit\Copy\models\DeployConfig;
use fortrabbit\Copy\Plugin;
use Symfony\Component\Process\Process;
use yii\console\ExitCode;
use yii\helpers\Inflector;

/**
 * Class SetupAction
 *
 * @package fortrabbit\Copy\commands
 */
class SetupAction extends \ostark\Yii2ArtisanBridge\base\Action
{

    /**
     * @var bool Verbose output
     */
    public $verbose = false;

    protected $sshUrl;

    /**
     * Setup your App
     *
     * @return int
     * @throws \fortrabbit\Copy\exceptions\CraftNotInstalledException
     * @throws \fortrabbit\Copy\exceptions\PluginNotInstalledException
     * @throws \fortrabbit\Copy\exceptions\RemoteException
     */
    public function run()
    {
        $this->input->setInteractive(true);
        $app = $this->ask("What's the name of your App?");
        $this->input->setInteractive($this->interactive);

        if (strlen($app) < 3 || strlen($app) > 16) {
            $this->errorBlock("Invalid App name.");

            return ExitCode::UNSPECIFIED_ERROR;
        }

        if (!$region = $this->guessRegion($app)) {
            $this->errorBlock('⚠  App not found');

            return ExitCode::UNSPECIFIED_ERROR;
        }


        $env = $this->anticipate("What's the environment?", ['production', 'staging'], 'production');

        // TODO: check if yaml exist
        $config = $this->writeDeployConfig($app, $region, Inflector::slug($env));

        // Perform exec checks
        $this->checkAndWrite("Testing DNS - " . Plugin::REGIONS[$region], true);
        $this->checkAndWrite("Testing rsync", $this->canExecBinary("rsync --help"));

        $mysql = $this->checkAndWrite("Testing mysqldump", $this->canExecBinary("mysqldump --help"));
        $ssh   = $this->checkAndWrite("Testing ssh access", $this->canExecBinary("ssh {$config->sshUrl} secrets"));


        if (!$this->confirm("Do you want to install and enable the plugin on the remote?", true)) {
            $this->noteBlock('Abort');

            return ExitCode::UNSPECIFIED_ERROR;
        }

        if (!$mysql) {
            $this->errorBlock('Mysqldump is required.');

            return ExitCode::UNSPECIFIED_ERROR;
        }

        if (!$ssh) {
            $this->errorBlock('SSH is required.');

            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ($this->setupRemote())
            ? ExitCode::OK
            : ExitCode::UNSPECIFIED_ERROR;
    }


    /**
     * @param string $app
     *
     * @return null|string
     */
    protected function guessRegion(string $app)
    {
        if ($records = dns_get_record("$app.frb.io", DNS_CNAME)) {
            return explode('.', $records[0]['target'])[1];
        }

        return null;
    }


    /**
     * @param string $cmd
     *
     * @return bool
     */
    protected function canExecBinary(string $cmd)
    {
        $proc     = new Process($cmd);
        $exitCode = $proc->run();

        return ($exitCode == 0) ? true : false;
    }

    /**
     * @param $app
     * @param $region
     * @param $env
     *
     * @return \fortrabbit\Copy\models\DeployConfig
     * @throws \yii\base\Exception
     */
    protected function writeDeployConfig($app, $region, $env)
    {
        $config            = new DeployConfig();
        $config->name      = $app;
        $config->sshUrl    = "{$app}@deploy.{$region}.frbit.com";
        $config->gitRemote = "$app/master";

        // Write yaml
        Plugin::getInstance()->config->setDeployEnviroment($env);
        Plugin::getInstance()->config->persist($config);

        // Write .env
        foreach ([Plugin::ENV_DEPLOY_ENVIRONMENT => $env] as $name => $value) {
            \Craft::$app->getConfig()->setDotEnvVar($name, $value);
            putenv("$name=$value");
        }

        return $config;

    }


    /**
     * @return bool
     * @throws \fortrabbit\Copy\exceptions\CraftNotInstalledException
     * @throws \fortrabbit\Copy\exceptions\PluginNotInstalledException
     * @throws \fortrabbit\Copy\exceptions\RemoteException
     */
    protected function setupRemote()
    {
        $plugin = Plugin::getInstance();
        $app    = $plugin->config->get()->name;

        if ($plugin->ssh->exec("ls vendor/bin/craft-copy-installer.php | wc -l")) {
            if (trim($plugin->ssh->getOutput()) != "1") {
                if ($this->confirm("The plugin is not installed on the remote! Do you want to deploy now?", true)) {
                    if (Craft::$app->runAction('copy/code/up', ['interactive' => $this->interactive]) != 0) {
                        return false;
                    }
                } else {
                    return false;
                }
            }
        }

        if ($plugin->ssh->exec('php vendor/bin/craft-copy-installer.php')) {
            $this->output->write($plugin->ssh->getOutput());
        };

        $this->output->type('php craft copy/db/up');

        if (Craft::$app->runAction('copy/db/up', ['interactive' => $this->interactive]) != 0) {
            return false;
        }

        $this->successBlock("Check it in the browser: https://{$app}.frb.io");

        return true;
    }


    protected function checkAndWrite($message, $success)
    {
        $this->output->write(PHP_EOL . $message);
        $this->output->write($success ? " <info>OK</info>" : " <error>⚠ Error</error>");

        return $success;
    }
}
