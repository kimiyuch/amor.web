// Reads one person's shifts from a roster photo with Claude.
import Anthropic from "https://esm.sh/@anthropic-ai/sdk@0.133.0?bundle";

const MAX_SIDE = 2000;
const MAX_UPSCALE = 2;
const MAX_TURNS = 14;
// Models that accept server-side fallbacks: "default".
const FALLBACK_MODELS = new Set([
  "claude-opus-5-5", "claude-opus-5", "claude-fable-5-1", "claude-fable-5", "claude-sonnet-5-5",
]);

const SYSTEM = `You read photographed hospital duty rosters (POLYPOINT/PEP "Dienstplan") and transcribe one person's shifts.

Layout: a grid with one row per employee (name, sometimes a staff number in brackets) and one column per day. The header shows month, weekday and day number per column; the title shows the full plan period (e.g. "01.12.2026 – 03.01.2027"). Cells hold short shift codes such as 1, 1T, 1E, 1P, M1, M3, 6A, 8B, 9A, 9B, 2S, 3F, B, HO, EI, DI. A cell with two stacked codes (e.g. "S1" above "BD") is one code written "S1/BD". Empty or grey cells mean no shift (code "").

The photos are hand-held: taken at an angle, folded, with glare, and rows and columns can be slanted. The overview image is downscaled, so do not read cell contents from it. Use the zoom tool, which crops the full-resolution photo. Zoom boxes are always in coordinates normalized to the whole original photo (0..1, origin top-left), never relative to an earlier crop.

How to work:
1. Find the title (plan period) and the person's row on the overview. Match the name flexibly: staff numbers, "Last First" vs "First Last", small spelling differences.
2. Zoom into the header to map each column to its date.
3. Zoom into the person's row about 7–10 days at a time, with enough vertical margin to see the neighbouring rows, so you don't drift onto the wrong row. Check the column alignment against the header where the photo is slanted.
4. Answer with one entry per date of the plan period, in order. Mark a cell uncertain when glare, a fold or blur makes it hard to read, or when you are not sure it belongs to this row.

row_box is the bounding box of the person's whole row (all day columns, plus the name) in normalized coordinates of the original photo. If the person is not in the roster, set person_found to false, return no days, and list the closest names you saw in notes. Keep notes short.`;

const ZOOM_TOOL = {
  name: "zoom",
  description:
    "Crop a region of the original full-resolution photo and return it as an image. Coordinates are normalized to the whole photo (0..1, x to the right, y down).",
  strict: true,
  eager_input_streaming: true,
  input_schema: {
    type: "object",
    properties: {
      x0: { type: "number", description: "Left edge, 0..1" },
      y0: { type: "number", description: "Top edge, 0..1" },
      x1: { type: "number", description: "Right edge, 0..1" },
      y1: { type: "number", description: "Bottom edge, 0..1" },
    },
    required: ["x0", "y0", "x1", "y1"],
    additionalProperties: false,
  },
};

const BOX = {
  type: "object",
  properties: {
    x0: { type: "number" }, y0: { type: "number" }, x1: { type: "number" }, y1: { type: "number" },
  },
  required: ["x0", "y0", "x1", "y1"],
  additionalProperties: false,
};

const RESULT_SCHEMA = {
  type: "object",
  properties: {
    person_found: { type: "boolean" },
    row_label: { type: "string", description: "The row's name exactly as printed" },
    period_start: { type: "string", description: "YYYY-MM-DD" },
    period_end: { type: "string", description: "YYYY-MM-DD" },
    row_box: BOX,
    days: {
      type: "array",
      items: {
        type: "object",
        properties: {
          date: { type: "string", description: "YYYY-MM-DD" },
          code: { type: "string", description: 'Shift code, "S1/BD" for stacked codes, "" for none' },
          uncertain: { type: "boolean" },
        },
        required: ["date", "code", "uncertain"],
        additionalProperties: false,
      },
    },
    notes: { type: "string" },
  },
  required: ["person_found", "row_label", "period_start", "period_end", "row_box", "days", "notes"],
  additionalProperties: false,
};

// ---------- images ----------

export async function loadPhoto(file) {
  return createImageBitmap(file, { imageOrientation: "from-image" });
}

/** Clamps and orders a normalized box; returns null when it is unusable. */
export function cleanBox(box) {
  const vals = [box?.x0, box?.y0, box?.x1, box?.y1];
  if (!vals.every((v) => typeof v === "number" && Number.isFinite(v))) return null;
  const c = (v) => Math.min(1, Math.max(0, v));
  const [x0, x1] = [c(Math.min(vals[0], vals[2])), c(Math.max(vals[0], vals[2]))];
  const [y0, y1] = [c(Math.min(vals[1], vals[3])), c(Math.max(vals[1], vals[3]))];
  if (x1 - x0 < 0.002 || y1 - y0 < 0.002) return null;
  return { x0, y0, x1, y1 };
}

/** Crops `box` (normalized) out of the bitmap, scaled to at most maxSide and at most MAX_UPSCALE×. */
export function crop(bitmap, box, maxSide = MAX_SIDE) {
  const sx = box.x0 * bitmap.width;
  const sy = box.y0 * bitmap.height;
  const sw = (box.x1 - box.x0) * bitmap.width;
  const sh = (box.y1 - box.y0) * bitmap.height;
  const scale = Math.min(maxSide / Math.max(sw, sh), MAX_UPSCALE);
  const canvas = document.createElement("canvas");
  canvas.width = Math.max(1, Math.round(sw * scale));
  canvas.height = Math.max(1, Math.round(sh * scale));
  const ctx = canvas.getContext("2d");
  ctx.imageSmoothingQuality = "high";
  ctx.drawImage(bitmap, sx, sy, sw, sh, 0, 0, canvas.width, canvas.height);
  return canvas;
}

function jpegBase64(canvas) {
  return canvas.toDataURL("image/jpeg", 0.88).split(",")[1];
}

const imageBlock = (canvas) => ({
  type: "image",
  source: { type: "base64", media_type: "image/jpeg", data: jpegBase64(canvas) },
});

// ---------- extraction ----------

function finalText(content) {
  // Text after the last fallback block is the answer of the model that finished.
  let text = "";
  for (const block of content) {
    if (block.type === "fallback") text = "";
    else if (block.type === "text") text += block.text;
  }
  return text;
}

function friendlyError(err) {
  if (err instanceof Anthropic.AuthenticationError) return new Error("The Anthropic API key was rejected. Check it in Settings.");
  if (err instanceof Anthropic.PermissionDeniedError) return new Error("This API key may not use the selected model.");
  if (err instanceof Anthropic.NotFoundError) return new Error("Model not found. Check the model in Settings.");
  if (err instanceof Anthropic.RateLimitError) return new Error("Rate limit reached. Wait a minute and try again.");
  if (err instanceof Anthropic.APIConnectionError) return new Error("No connection to Anthropic. Check your internet connection.");
  if (err instanceof Anthropic.APIError) return new Error(`Anthropic error ${err.status ?? ""}: ${err.message}`);
  return err;
}

/**
 * @returns {Promise<{result: object, usage: {input: number, output: number}, turns: number}>}
 */
export async function extractShifts({ bitmap, person, apiKey, model, onStatus, signal }) {
  const client = new Anthropic({ apiKey, dangerouslyAllowBrowser: true });
  const overview = crop(bitmap, { x0: 0, y0: 0, x1: 1, y1: 1 });
  const today = new Date().toISOString().slice(0, 10);
  const messages = [
    {
      role: "user",
      content: [
        imageBlock(overview),
        {
          type: "text",
          text:
            `Person: ${person}\n` +
            `Today: ${today}\n` +
            `This overview is the whole photo downscaled to ${overview.width}×${overview.height} px; ` +
            `the original is ${bitmap.width}×${bitmap.height} px. ` +
            `Zoom into the header and this person's row before answering.`,
        },
      ],
    },
  ];
  const useFallbacks = FALLBACK_MODELS.has(model);
  const usage = { input: 0, output: 0 };
  let jsonRetries = 0;

  for (let turn = 1; turn <= MAX_TURNS; turn++) {
    const lastTurn = turn === MAX_TURNS;
    onStatus?.(turn === 1 ? "Looking at the roster…" : "Reading the row…");

    let message;
    try {
      const stream = client.beta.messages.stream(
        {
          model,
          max_tokens: 32000,
          system: SYSTEM,
          thinking: { type: "adaptive" },
          output_config: { effort: "high", format: { type: "json_schema", schema: RESULT_SCHEMA } },
          tools: [ZOOM_TOOL],
          tool_choice: lastTurn ? { type: "none" } : { type: "auto" },
          cache_control: { type: "ephemeral" },
          messages,
          ...(useFallbacks ? { betas: ["server-side-fallback-2026-07-01"], fallbacks: "default" } : {}),
        },
        { signal },
      );
      message = await stream.finalMessage();
      jsonRetries = 0;
    } catch (err) {
      if (signal?.aborted) throw new DOMException("Cancelled", "AbortError");
      // Only an unparseable streamed tool input is retried; API errors are surfaced.
      if (err instanceof Anthropic.APIError || jsonRetries++ >= 2) throw friendlyError(err);
      turn--;
      continue;
    }
    usage.input += (message.usage.input_tokens ?? 0) + (message.usage.cache_read_input_tokens ?? 0) +
      (message.usage.cache_creation_input_tokens ?? 0);
    usage.output += message.usage.output_tokens ?? 0;

    if (message.stop_reason === "refusal") {
      const category = message.stop_details?.category;
      throw new Error(`Claude declined to read this photo${category ? ` (${category})` : ""}. Try another photo.`);
    }
    if (message.stop_reason === "max_tokens") {
      throw new Error("Claude's answer was cut off. Try again, or crop the photo to the roster.");
    }
    if (message.stop_reason === "pause_turn") {
      messages.push({ role: "assistant", content: message.content });
      continue;
    }

    const toolUses = message.content.filter((b) => b.type === "tool_use");
    if (toolUses.length === 0) {
      const text = finalText(message.content);
      try {
        return { result: JSON.parse(text), usage, turns: turn };
      } catch {
        throw new Error("Claude's answer could not be read. Please try again.");
      }
    }

    messages.push({ role: "assistant", content: message.content });
    const results = toolUses.map((tool) => {
      const box = tool.name === "zoom" ? cleanBox(tool.input) : null;
      if (!box) {
        return {
          type: "tool_result",
          tool_use_id: tool.id,
          is_error: true,
          content: JSON.stringify({ INVALID_INPUT: JSON.stringify(tool.input) }),
        };
      }
      onStatus?.("Zooming…");
      const canvas = crop(bitmap, box);
      return {
        type: "tool_result",
        tool_use_id: tool.id,
        content: [
          { type: "text", text: `Crop x ${box.x0.toFixed(3)}–${box.x1.toFixed(3)}, y ${box.y0.toFixed(3)}–${box.y1.toFixed(3)} (${canvas.width}×${canvas.height} px)` },
          imageBlock(canvas),
        ],
      };
    });
    const content = [...results];
    if (turn === MAX_TURNS - 1) {
      content.push({ type: "text", text: "That was the last zoom. Give your final answer now." });
    }
    messages.push({ role: "user", content });
  }
  throw new Error("Claude did not finish reading the roster. Please try again.");
}
