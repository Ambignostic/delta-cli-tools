<VirtualHost *:81>
    EnableSendfile off
    DocumentRoot <?php echo $this->docRoot . PHP_EOL;?>
    ServerName <?php echo $this->hostname . PHP_EOL;?>
    SetEnv APPLICATION_ENV "<?php echo $this->applicationEnv;?>"

    <Directory <?php echo $this->docRoot;?>>
        AllowOverride All
    </Directory>
</VirtualHost>
