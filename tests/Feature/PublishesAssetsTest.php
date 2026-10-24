<?php

namespace Puneetpal\DeviceTokens\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\Test;
use Puneetpal\DeviceTokens\Tests\TestCase;

class PublishesAssetsTest extends TestCase
{
    protected string $publishRoot;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $this->publishRoot = sys_get_temp_dir().'/laravel-device-tokens-publish-'.uniqid();

        (new Filesystem)->ensureDirectoryExists($this->publishRoot.'/config');
        (new Filesystem)->ensureDirectoryExists($this->publishRoot.'/database/migrations');

        $app->useConfigPath($this->publishRoot.'/config');
        $app->useDatabasePath($this->publishRoot.'/database');
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->publishRoot);

        parent::tearDown();
    }

    #[Test]
    public function the_config_file_is_published_under_its_documented_tag(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'device-tokens-config'])->assertSuccessful();

        $published = $this->publishRoot.'/config/device-tokens.php';

        $this->assertFileExists($published);
        $this->assertFileEquals(__DIR__.'/../../config/device-tokens.php', $published);
    }

    #[Test]
    public function the_migration_is_published_under_its_documented_tag(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'device-tokens-migrations'])->assertSuccessful();

        $published = glob($this->publishRoot.'/database/migrations/*_add_device_metadata_to_personal_access_tokens_table.php');

        $this->assertCount(1, $published);
        $this->assertStringContainsString("'platform'", (string) file_get_contents($published[0]));
    }
}
