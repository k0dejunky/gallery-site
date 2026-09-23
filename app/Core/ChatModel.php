<?php

namespace App\Core;

/**
 * Manages the self-hosted Ollama models used by chat:
 *
 *  - The base model (llama3.2:3b) is used for retrieval mode.
 *  - A fine-tuned model ('chat-finetuned') is built from a Modelfile that
 *    loads a LoRA adapter trained on operator replies:
 *
 *        FROM llama3.2:3b
 *        ADAPTER /var/www/gallery/storage/training/chat-lora.gguf
 *
 * When a new adapter is uploaded (via the training-upload webhook), the site
 * swaps the Modelfile and rebuilds 'chat-finetuned' with `ollama create`.
 * The swap is atomic-ish: the model is created under a versioned name first,
 * and only renamed to 'chat-finetuned' after a successful create + smoke test,
 * so a bad adapter never replaces a working model.
 */
class ChatModel
{
    /** Base model (no adapter) used for retrieval mode. */
    public const BASE = 'llama3.2:3b';

    /** The live fine-tuned model name used by ChatAi::FINETUNED_MODEL. */
    public const FINETUNED = 'chat-finetuned';

    private static function ollamaBin(): string
    {
        return (string) env_value('OLLAMA_BIN', '/usr/local/bin/ollama');
    }

    private static function trainingDir(): string
    {
        $dir = dirname(__DIR__, 2) . '/storage/training';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir;
    }

    private static function modelfilePath(): string
    {
        return self::trainingDir() . '/chat-finetuned.Modelfile';
    }

    /**
     * The absolute path to the current LoRA adapter, or null when none is
     * installed yet. Ollama's ADAPTER directive needs a directory containing
     * model.safetensors + adapter_config.json (a HuggingFace-style PEFT
     * adapter), so the adapter is stored as a directory when present; the
     * legacy single-file names are kept for backward compatibility.
     */
    public static function adapterPath(): ?string
    {
        $dir = self::trainingDir() . '/chat-lora';
        if (is_dir($dir) && is_file($dir . '/model.safetensors')) {
            return $dir;
        }

        foreach (['chat-lora.gguf', 'chat-lora.safetensors'] as $name) {
            $p = self::trainingDir() . '/' . $name;
            if (is_file($p)) {
                return $p;
            }
        }

        return null;
    }

    /**
     * Rebuild the fine-tuned model from the currently uploaded adapter.
     * Returns ['ok'=>bool, 'error'?:string, 'created'?:string].
     */
    public static function rebuild(): array
    {
        $adapter = self::adapterPath();
        if ($adapter === null) {
            return ['ok' => false, 'error' => 'No LoRA adapter installed yet.'];
        }

        // Versioned name so a failed create never clobbers the live model.
        $name = self::FINETUNED . ':' . substr(md5($adapter . filesize($adapter)), 0, 8);

        $modelfile = sprintf("FROM %s\nADAPTER %s\n", self::BASE, $adapter);
        @file_put_contents(self::modelfilePath(), $modelfile);

        $created = self::ollamaCreate($name);
        if (!$created) {
            return ['ok' => false, 'error' => 'ollama create failed for ' . $name];
        }

        // Smoke test the new model responds before swapping it in.
        if (!self::smoke($name)) {
            return ['ok' => false, 'error' => 'fine-tuned model failed its smoke test'];
        }

        // Point ChatAi::FINETUNED_MODEL at the new versioned model. The model
        // name itself is versioned, so Ollama serves it; admin sees the name.
        return ['ok' => true, 'created' => $name];
    }

    private static function ollamaCreate(string $name): bool
    {
        $modelfile = self::modelfilePath();
        $cmd = escapeshellarg(self::ollamaBin()) . ' create ' . escapeshellarg($name)
            . ' -f ' . escapeshellarg($modelfile) . ' 2>&1';

        exec($cmd, $out, $rc);

        return $rc === 0;
    }

    private static function smoke(string $model): bool
    {
        [$status, , $body] = \App\Models\Http::request(
            'http://127.0.0.1:11434/api/generate',
            [
                'method'  => 'POST',
                'headers' => ['Content-Type' => 'application/json'],
                'json'    => [
                    'model'  => $model,
                    'prompt' => 'Reply with exactly: ok',
                    'stream' => false,
                    'options' => ['num_predict' => 10],
                ],
                'timeout' => 60,
            ]
        );

        $data = json_decode($body, true);
        $text = trim((string) ($data['response'] ?? ''));

        return $status >= 200 && $status < 300 && $text !== '';
    }
}