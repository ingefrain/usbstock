<?php

namespace Tests\Feature;

use App\Commerce\SupplierDownloader;
use App\Commerce\SupplierTransferException;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

class SupplierTransportContractTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_confirmed_ftp_contract_and_tls_modes_use_configured_parameters_without_network(): void
    {
        require base_path('tests/Support/supplier_curl_double.php');
        config(['commerce.sync.ftp_enabled' => true, 'commerce.sync.ftp_host' => 'supplier.example.test',
            'commerce.sync.ftp_port' => 21, 'commerce.sync.ftp_path' => '/FTP/Existencias-ftp-precio.csv',
            'commerce.sync.ftp_user' => 'synthetic-reader', 'commerce.sync.ftp_password' => 'synthetic-password']);
        foreach (['ftp' => 'ftp', 'ftps-explicit' => 'ftp', 'ftps-implicit' => 'ftps'] as $mode => $scheme) {
            config(['commerce.sync.ftp_protocol' => $mode]);
            $this->assertStringContainsString('TEST-A,0,,10', app(SupplierDownloader::class)->download());
            $capture = $GLOBALS['supplier_curl_double'];
            $this->assertSame($scheme.'://supplier.example.test:21/FTP/Existencias-ftp-precio.csv', $capture->url);
            $this->assertSame($mode === 'ftp' ? CURLUSESSL_NONE : CURLUSESSL_ALL, $capture->options[CURLOPT_USE_SSL]);
            $this->assertTrue($capture->options[CURLOPT_SSL_VERIFYPEER]);
            $this->assertSame(2, $capture->options[CURLOPT_SSL_VERIFYHOST]);
            $this->assertFalse($capture->options[CURLOPT_FOLLOWLOCATION]);
            $this->assertSame(15, $capture->options[CURLOPT_CONNECTTIMEOUT]);
            $this->assertSame(120, $capture->options[CURLOPT_TIMEOUT]);
        }
        config(['commerce.sync.ftp_enabled' => false]);
        $this->expectException(SupplierTransferException::class);
        app(SupplierDownloader::class)->download();
    }
}
