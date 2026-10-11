<?php

namespace App\Roster;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\NotFoundException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;

/**
 * Reads a roster with Claude: a downscaled overview plus a zoom tool that crops the
 * full-resolution photo, until Claude answers with the person's days as JSON.
 */
final class ClaudeRosterReader implements RosterReader
{
    private const MAX_TURNS = 14;

    // Models that accept server-side fallbacks: "default".
    private const FALLBACK_MODELS = ['claude-opus-5-5', 'claude-opus-5', 'claude-fable-5-1', 'claude-fable-5', 'claude-sonnet-5-5'];

    private const SYSTEM = <<<'TXT'
        You read photographed hospital duty rosters (POLYPOINT/PEP "Dienstplan") and transcribe one person's shifts.

        Layout: a grid with one row per employee (name, sometimes a staff number in brackets) and one column per day. The header shows month, weekday and day number per column; the title shows the full plan period (e.g. "01.12.2026 – 03.01.2027"). Cells hold short shift codes such as 1, 1T, 1E, 1P, M1, M3, 6A, 8B, 9A, 9B, 2S, 3F, B, HO, EI, DI. A cell with two stacked codes (e.g. "S1" above "BD") is one code written "S1/BD". Empty or grey cells mean no shift (code "").

        The photos are hand-held: taken at an angle, folded, with glare, and rows and columns can be slanted. The overview image is downscaled, so do not read cell contents from it. Use the zoom tool, which crops the full-resolution photo. Zoom boxes are always in coordinates normalized to the whole original photo (0..1, origin top-left), never relative to an earlier crop.

        How to work:
        1. Find the title (plan period) and the person's row on the overview. Match the name flexibly: staff numbers, "Last First" vs "First Last", small spelling differences.
        2. Zoom into the header to map each column to its date.
        3. Zoom into the person's row about 7–10 days at a time, with enough vertical margin to see the neighbouring rows, so you don't drift onto the wrong row. Check the column alignment against the header where the photo is slanted.
        4. Answer with one entry per date of the plan period, in order. Mark a cell uncertain when glare, a fold or blur makes it hard to read, or when you are not sure it belongs to this row.

        row_box is the bounding box of the person's whole row (all day columns, plus the name) in normalized coordinates of the original photo. If the person is not in the roster, set person_found to false, return no days, and list the closest names you saw in notes. Keep notes short.
        TXT;

    public function __construct(private string $apiKey, private string $model) {}

    public function read(Photo $photo, string $person, callable $onStatus): array
    {
        if ($this->apiKey === '') {
            throw new RosterReadException('The Anthropic API key is missing. Set ANTHROPIC_API_KEY in .env.');
        }
        $client = new Client(apiKey: $this->apiKey);
        $overview = $photo->crop(['x0' => 0, 'y0' => 0, 'x1' => 1, 'y1' => 1]);
        $messages = [[
            'role' => 'user',
            'content' => [
                self::image($overview['jpeg']),
                ['type' => 'text', 'text' => "Person: {$person}\n"
                    .'Today: '.now()->toDateString()."\n"
                    ."This overview is the whole photo downscaled to {$overview['width']}×{$overview['height']} px; "
                    ."the original is {$photo->width()}×{$photo->height()} px. "
                    .'Zoom into the header and this person\'s row before answering.'],
            ],
        ]];
        $fallbacks = in_array($this->model, self::FALLBACK_MODELS, true);

        for ($turn = 1; $turn <= self::MAX_TURNS; $turn++) {
            $onStatus($turn === 1 ? 'Looking at the roster…' : 'Reading the row…');
            try {
                $response = $client->beta->messages->create(
                    model: $this->model,
                    maxTokens: 16000,
                    system: self::SYSTEM,
                    thinking: ['type' => 'adaptive'],
                    outputConfig: ['effort' => 'high', 'format' => ['type' => 'json_schema', 'schema' => self::resultSchema()]],
                    tools: [self::zoomTool()],
                    toolChoice: $turn === self::MAX_TURNS ? ['type' => 'none'] : ['type' => 'auto'],
                    cacheControl: ['type' => 'ephemeral'],
                    messages: $messages,
                    betas: $fallbacks ? ['server-side-fallback-2026-07-01'] : null,
                    fallbacks: $fallbacks ? 'default' : null,
                    requestOptions: ['timeout' => 600],
                );
            } catch (APIStatusException|APIConnectionException $e) {
                throw new RosterReadException(self::describe($e), previous: $e);
            }

            if ($response->stopReason === 'refusal') {
                $category = $response->stopDetails?->category;
                throw new RosterReadException('Claude declined to read this photo'.($category ? " ({$category})" : '').'. Try another photo.');
            }
            if ($response->stopReason === 'max_tokens') {
                throw new RosterReadException('Claude\'s answer was cut off. Please try again.');
            }

            $toolUses = array_values(array_filter($response->content, fn ($b) => $b->type === 'tool_use'));
            if ($toolUses === [] && $response->stopReason !== 'pause_turn') {
                $result = json_decode(self::finalText($response->content), true);
                if (! is_array($result) || ! isset($result['days'])) {
                    throw new RosterReadException('Claude\'s answer could not be read. Please try again.');
                }

                return $result;
            }

            $messages[] = ['role' => 'assistant', 'content' => $response->content];
            if ($response->stopReason === 'pause_turn') {
                continue;
            }

            $content = [];
            foreach ($toolUses as $tool) {
                $box = $tool->name === 'zoom' ? Photo::cleanBox($tool->input) : null;
                if ($box === null) {
                    $content[] = ['type' => 'tool_result', 'toolUseID' => $tool->id, 'isError' => true,
                        'content' => json_encode(['INVALID_INPUT' => $tool->input])];

                    continue;
                }
                $onStatus('Zooming in…');
                $crop = $photo->crop($box);
                $content[] = ['type' => 'tool_result', 'toolUseID' => $tool->id, 'content' => [
                    ['type' => 'text', 'text' => sprintf('Crop x %.3f–%.3f, y %.3f–%.3f (%d×%d px)',
                        $box['x0'], $box['x1'], $box['y0'], $box['y1'], $crop['width'], $crop['height'])],
                    self::image($crop['jpeg']),
                ]];
            }
            if ($turn === self::MAX_TURNS - 1) {
                $content[] = ['type' => 'text', 'text' => 'That was the last zoom. Give your final answer now.'];
            }
            $messages[] = ['role' => 'user', 'content' => $content];
        }

        throw new RosterReadException('Claude did not finish reading the roster. Please try again.');
    }

    private static function image(string $jpeg): array
    {
        return ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => 'image/jpeg', 'data' => base64_encode($jpeg)]];
    }

    /** Text after the last fallback block is the answer of the model that finished. */
    private static function finalText(array $content): string
    {
        $text = '';
        foreach ($content as $block) {
            if ($block->type === 'fallback') {
                $text = '';
            } elseif ($block->type === 'text') {
                $text .= $block->text;
            }
        }

        return $text;
    }

    private static function describe(APIStatusException|APIConnectionException $e): string
    {
        return match (true) {
            $e instanceof AuthenticationException => 'The Anthropic API key was rejected. Check ANTHROPIC_API_KEY in .env.',
            $e instanceof PermissionDeniedException => 'This API key may not use the selected model.',
            $e instanceof NotFoundException => 'Model not found. Check AMOR_MODEL in .env.',
            $e instanceof RateLimitException => 'Claude is busy right now. Wait a minute and try again.',
            $e instanceof APIConnectionException => 'Amor could not reach Claude. Please try again.',
            default => 'Claude reported an error ('.($e->status ?? '?').'). Please try again.',
        };
    }

    private static function zoomTool(): array
    {
        return [
            'name' => 'zoom',
            'description' => 'Crop a region of the original full-resolution photo and return it as an image. Coordinates are normalized to the whole photo (0..1, x to the right, y down).',
            'strict' => true,
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'x0' => ['type' => 'number', 'description' => 'Left edge, 0..1'],
                    'y0' => ['type' => 'number', 'description' => 'Top edge, 0..1'],
                    'x1' => ['type' => 'number', 'description' => 'Right edge, 0..1'],
                    'y1' => ['type' => 'number', 'description' => 'Bottom edge, 0..1'],
                ],
                'required' => ['x0', 'y0', 'x1', 'y1'],
                'additionalProperties' => false,
            ],
        ];
    }

    private static function resultSchema(): array
    {
        $box = [
            'type' => 'object',
            'properties' => ['x0' => ['type' => 'number'], 'y0' => ['type' => 'number'], 'x1' => ['type' => 'number'], 'y1' => ['type' => 'number']],
            'required' => ['x0', 'y0', 'x1', 'y1'],
            'additionalProperties' => false,
        ];

        return [
            'type' => 'object',
            'properties' => [
                'person_found' => ['type' => 'boolean'],
                'row_label' => ['type' => 'string', 'description' => 'The row\'s name exactly as printed'],
                'period_start' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                'period_end' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                'row_box' => $box,
                'days' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                            'code' => ['type' => 'string', 'description' => 'Shift code, "S1/BD" for stacked codes, "" for none'],
                            'uncertain' => ['type' => 'boolean'],
                        ],
                        'required' => ['date', 'code', 'uncertain'],
                        'additionalProperties' => false,
                    ],
                ],
                'notes' => ['type' => 'string'],
            ],
            'required' => ['person_found', 'row_label', 'period_start', 'period_end', 'row_box', 'days', 'notes'],
            'additionalProperties' => false,
        ];
    }
}
