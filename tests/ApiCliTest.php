<?php

declare(strict_types=1);

namespace EidCloud\ApiCli\Tests;

require_once __DIR__ . '/../src/Autoloader.php';
\EidCloud\ApiCli\Autoloader::register();

use EidCloud\ApiCli\Parser\SpecParser;
use EidCloud\ApiCli\CliGenerator;
use EidCloud\ApiCli\Validator\RequestValidator;
use EidCloud\ApiCli\Formatter\TableFormatter;
use EidCloud\ApiCli\Auth\AuthManager;
use EidCloud\ApiCli\Command\PaginationHandler;
use EidCloud\ApiCli\Client\HttpClient;

class ApiCliTest
{
    private int $passes = 0;
    private int $fails = 0;
    private array $errors = [];

    public function run(): bool
    {
        echo "Running EidCloud API CLI Test Suite...\n";
        echo "=================================================\n";

        $this->testSpecParser();
        $this->testRequestValidator();
        $this->testTableFormatter();
        $this->testAuthManager();
        $this->testCliGenerator();
        $this->testPaginationExtraction();

        echo "=================================================\n";
        echo "Tests completed: " . ($this->passes + $this->fails) . "\n";
        echo "Passed: \033[32m{$this->passes}\033[0m\n";
        if ($this->fails > 0) {
            echo "Failed: \033[31m{$this->fails}\033[0m\n";
            foreach ($this->errors as $err) {
                echo "  - {$err}\n";
            }
            return false;
        }

        echo "\033[32m✔ 100% of tests passed successfully!\033[0m\n";
        return true;
    }

    private function assert(bool $condition, string $testName): void
    {
        if ($condition) {
            $this->passes++;
            echo "  [PASS] {$testName}\n";
        } else {
            $this->fails++;
            $this->errors[] = "Assertion failed for: {$testName}";
            echo "  [FAIL] {$testName}\n";
        }
    }

    private function testSpecParser(): void
    {
        echo "\n[SpecParser Tests]\n";
        $specPath = __DIR__ . '/../examples/sample-openapi.json';
        $parser = new SpecParser($specPath);

        $this->assert($parser->getTitle() === 'EidCloud Sample API', 'Parser extracts correct title');
        $this->assert($parser->getBaseUrl() === 'https://jsonplaceholder.typicode.com', 'Parser extracts correct base URL');

        $ops = $parser->getOperations();
        $this->assert(count($ops) >= 4, 'Parser extracts all operations');

        $userOps = array_filter($ops, fn($o) => $o['resource'] === 'users');
        $this->assert(count($userOps) === 4, 'Parser groups operations under correct resources');
    }

    private function testRequestValidator(): void
    {
        echo "\n[RequestValidator Tests]\n";
        $validator = new RequestValidator();

        $specs = [
            ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
            ['name' => 'active', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'boolean']]
        ];

        // Valid input
        $res = $validator->validateParameters($specs, ['id' => '42', 'active' => 'true']);
        $this->assert($res['valid'] === true, 'Validator accepts valid parameters');
        $this->assert($res['coerced']['id'] === 42, 'Validator coerces integer');
        $this->assert($res['coerced']['active'] === true, 'Validator coerces boolean');

        // Missing required
        $invalid = $validator->validateParameters($specs, []);
        $this->assert($invalid['valid'] === false, 'Validator catches missing required parameters');

        // Invalid type
        $invalidType = $validator->validateParameters($specs, ['id' => 'not-a-number']);
        $this->assert($invalidType['valid'] === false, 'Validator catches type mismatch');
    }

    private function testTableFormatter(): void
    {
        echo "\n[TableFormatter Tests]\n";
        $formatter = new TableFormatter(false); // Color disabled for exact matching

        $rows = [
            ['id' => 1, 'name' => 'Shadi', 'role' => 'Architect'],
            ['id' => 2, 'name' => 'Alice', 'role' => 'Engineer']
        ];

        $table = $formatter->renderTable($rows);
        $this->assert(str_contains($table, 'Shadi'), 'Table renders row data');
        $this->assert(str_contains($table, 'Architect'), 'Table renders headers and columns');

        $csv = $formatter->renderCsv($rows);
        $this->assert(str_contains($csv, 'id,name,role'), 'CSV renders headers');
        $this->assert(str_contains($csv, '1,Shadi,Architect'), 'CSV renders records');

        $json = $formatter->renderJson(['status' => 'ok']);
        $this->assert(str_contains($json, '"status": "ok"'), 'JSON renders formatted structure');
    }

    private function testAuthManager(): void
    {
        echo "\n[AuthManager Tests]\n";
        $tmpConfig = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_auth_' . uniqid() . '.json';
        $auth = new AuthManager($tmpConfig);

        $auth->setProfile('prod', [
            'type' => 'bearer',
            'token' => 'sec_token_999'
        ]);

        $retrieved = $auth->getProfile('prod');
        $this->assert($retrieved !== null && $retrieved['token'] === 'sec_token_999', 'Auth profiles saved and decrypted accurately');

        $headers = [];
        $query = [];
        $auth->applyAuth($retrieved, $headers, $query);
        $this->assert(isset($headers['Authorization']) && $headers['Authorization'] === 'Bearer sec_token_999', 'Bearer token applied to headers');

        $auth->removeProfile('prod');
        $this->assert($auth->getProfile('prod') === null, 'Auth profile removal works');

        @unlink($tmpConfig);
    }

    private function testCliGenerator(): void
    {
        echo "\n[CliGenerator Tests]\n";
        $specPath = __DIR__ . '/../examples/sample-openapi.json';
        $parser = new SpecParser($specPath);
        $generator = new CliGenerator($parser);

        $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_cli_' . uniqid();
        $target = $generator->generate('sample-api', $tmpDir);

        $this->assert(file_exists($target), 'CLI generator successfully compiles executable script');
        $content = file_get_contents($target);
        $this->assert(str_contains($content, 'EidCloud Sample API'), 'Compiled CLI includes spec metadata');

        // Cleanup
        @unlink($target);
        @unlink($target . '.bat');
        @rmdir($tmpDir);
    }

    private function testPaginationExtraction(): void
    {
        echo "\n[PaginationHandler Tests]\n";
        $client = new HttpClient('http://localhost');
        $handler = new PaginationHandler($client);

        $data1 = ['data' => [['id' => 1], ['id' => 2]]];
        $items1 = $handler->extractItemsFromResponse($data1);
        $this->assert(count($items1) === 2, 'Extracts items from wrapper field');

        $data2 = [['id' => 3], ['id' => 4]];
        $items2 = $handler->extractItemsFromResponse($data2);
        $this->assert(count($items2) === 2, 'Extracts raw list array');
    }
}
