<?php

class PluginTest extends TestCase
{
    public function test_plugin_installed() {
        activate_plugin( 'disciple-tools-homescreen-apps/disciple-tools-homescreen-apps.php' );

        $this->assertContains(
            'disciple-tools-homescreen-apps/disciple-tools-homescreen-apps.php',
            get_option( 'active_plugins' )
        );
    }
}
