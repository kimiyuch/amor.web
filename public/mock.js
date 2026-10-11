// Test double for ?mock: fakes the Anthropic SSE stream (with one zoom round trip),
// Google sign-in and the Google Calendar API. Not deployed (see deploy.sh).
import { addDays } from "./calendar.js";

const realFetch = window.fetch.bind(window);
const mock = (window.__mock = { requests: [], checks: [], calendar: new Map(), calls: [] });

const PERIOD = ["2026-12-01", "2027-01-03"];
const PATTERN = ["1", "1T", "", "", "M1", "S1/BD", "HO", "9A", "", "B", "X7", "1E", "", "6A"];

function finalAnswer() {
  const days = [];
  for (let d = PERIOD[0], i = 0; d <= PERIOD[1]; d = addDays(d, 1), i++) {
    days.push({ date: d, code: PATTERN[i % PATTERN.length], uncertain: i === 4 });
  }
  return {
    person_found: true,
    row_label: "Muster Anna (4711)",
    period_start: PERIOD[0],
    period_end: PERIOD[1],
    row_box: { x0: 0.03, y0: 0.235, x1: 0.97, y1: 0.29 },
    days,
    notes: "Glare over 05.12.; please check that cell.",
  };
}

function sse(events) {
  const body = events.map((e) => `event: ${e.type}\ndata: ${JSON.stringify(e)}\n\n`).join("");
  const bytes = new TextEncoder().encode(body);
  const stream = new ReadableStream({
    async start(controller) {
      // Deliver in chunks with a short delay so the progress status is visible.
      for (let i = 0; i < bytes.length; i += 64) {
        controller.enqueue(bytes.slice(i, i + 64));
        await new Promise((r) => setTimeout(r, 4));
      }
      controller.close();
    },
  });
  return new Response(stream, { status: 200, headers: { "content-type": "text/event-stream", "request-id": "req_mock" } });
}

function message(n, blocks, stopReason) {
  const events = [{
    type: "message_start",
    message: {
      id: `msg_mock_${n}`, type: "message", role: "assistant", model: "claude-opus-5-5", content: [],
      stop_reason: null, stop_sequence: null, usage: { input_tokens: 1500, output_tokens: 1 },
    },
  }];
  blocks.forEach((block, index) => {
    if (block.type === "thinking") {
      events.push(
        { type: "content_block_start", index, content_block: { type: "thinking", thinking: "", signature: "" } },
        { type: "content_block_delta", index, delta: { type: "signature_delta", signature: "sig_mock" } },
      );
    } else if (block.type === "tool_use") {
      const json = JSON.stringify(block.input);
      events.push({ type: "content_block_start", index, content_block: { type: "tool_use", id: block.id, name: block.name, input: {} } });
      for (let i = 0; i < json.length; i += 12) {
        events.push({ type: "content_block_delta", index, delta: { type: "input_json_delta", partial_json: json.slice(i, i + 12) } });
      }
    } else {
      events.push({ type: "content_block_start", index, content_block: { type: "text", text: "" } });
      for (let i = 0; i < block.text.length; i += 80) {
        events.push({ type: "content_block_delta", index, delta: { type: "text_delta", text: block.text.slice(i, i + 80) } });
      }
    }
    events.push({ type: "content_block_stop", index });
  });
  events.push(
    { type: "message_delta", delta: { stop_reason: stopReason, stop_sequence: null }, usage: { output_tokens: 400 } },
    { type: "message_stop" },
  );
  return sse(events);
}

function check(name, ok) {
  mock.checks.push({ name, ok: Boolean(ok) });
}

function anthropic(body, headers) {
  mock.requests.push(body);
  const n = mock.requests.length;
  if (n === 1) {
    check("model", body.model === "claude-opus-5-5");
    check("stream", body.stream === true);
    check("effort high", body.output_config?.effort === "high");
    check("json_schema format", body.output_config?.format?.type === "json_schema");
    check("fallbacks default", body.fallbacks === "default");
    check("fallback beta header", (headers.get("anthropic-beta") ?? "").includes("server-side-fallback-2026-07-01"));
    check("browser access header", headers.get("anthropic-dangerous-direct-browser-access") === "true");
    check("zoom tool strict", body.tools?.[0]?.name === "zoom" && body.tools[0].strict === true);
    check("overview image ≤ 2000 px sent", body.messages[0].content[0].type === "image");
    return message(n, [
      { type: "thinking" },
      { type: "tool_use", id: "toolu_mock_1", name: "zoom", input: { x0: 0.02, y0: 0.4, x1: 0.35, y1: 0.5 } },
    ], "tool_use");
  }
  const last = body.messages.at(-1);
  const result = last.content.find((b) => b.type === "tool_result");
  check("history is append-only", body.messages.length === 3 && body.messages[1].role === "assistant");
  check("thinking block echoed back", body.messages[1].content[0].type === "thinking");
  check("tool_result for zoom", result?.tool_use_id === "toolu_mock_1");
  check("tool_result contains crop image", result?.content?.some((b) => b.type === "image" && b.source.data.length > 1000));
  return message(n, [{ type: "thinking" }, { type: "text", text: JSON.stringify(finalAnswer()) }], "end_turn");
}

// ---------- Google ----------

window.google = {
  accounts: {
    oauth2: {
      initTokenClient: ({ callback }) => ({
        requestAccessToken: () => {
          mock.calls.push("token");
          setTimeout(() => callback({ access_token: "mock-token", expires_in: 3600 }), 50);
        },
      }),
    },
  },
};

function googleCalendar(method, url, body) {
  mock.calls.push(`${method} ${url.pathname.replace(/^.*\/events/, "/events")}`);
  const json = (status, data) =>
    new Response(data === undefined ? null : JSON.stringify(data), { status, headers: { "content-type": "application/json" } });
  const id = decodeURIComponent(url.pathname.split("/events/")[1] ?? "");
  if (method === "PUT") {
    if (!mock.calendar.has(id)) return json(404, { error: { code: 404, message: "Not Found" } });
    mock.calendar.set(id, body);
    return json(200, body);
  }
  if (method === "POST") {
    mock.calendar.set(body.id, body);
    return json(200, body);
  }
  if (method === "DELETE") {
    if (!mock.calendar.has(id)) return json(410, { error: { code: 410, message: "Gone" } });
    mock.calendar.delete(id);
    return new Response(null, { status: 204 });
  }
  return json(400, { error: { message: "unsupported" } });
}

window.fetch = async (input, init = {}) => {
  const request = input instanceof Request ? input : null;
  const url = new URL(request ? request.url : String(input));
  const method = (init.method ?? request?.method ?? "GET").toUpperCase();
  const headers = new Headers(init.headers ?? request?.headers);
  const text = init.body ?? (request ? await request.clone().text() : null);
  const body = typeof text === "string" && text ? JSON.parse(text) : undefined;
  if (url.hostname === "api.anthropic.com") return anthropic(body, headers);
  if (url.hostname === "www.googleapis.com") return googleCalendar(method, url, body);
  return realFetch(input, init);
};

// ---------- synthetic roster photo ----------

mock.rosterFile = async () => {
  const canvas = Object.assign(document.createElement("canvas"), { width: 3200, height: 2200 });
  const ctx = canvas.getContext("2d");
  ctx.fillStyle = "#e9e6dc";
  ctx.fillRect(0, 0, canvas.width, canvas.height);
  ctx.translate(1600, 1100);
  ctx.rotate(-0.025);
  ctx.translate(-1600, -1100);
  ctx.fillStyle = "#fbfaf6";
  ctx.fillRect(80, 80, 3040, 2040);
  ctx.fillStyle = "#111";
  ctx.font = "bold 54px sans-serif";
  ctx.fillText("Dienstplan Pflege Anästhesie Waid – 01.12.2026 – 03.01.2027", 120, 170);
  const names = ["Beispiel Hans (4702)", "Keller Sara (4705)", "Muster Anna (4711)", "Meier Luca (4720)", "Rossi Elena (4733)"];
  const x0 = 520, colW = 76, rowH = 90, top = 330;
  ctx.font = "24px sans-serif";
  for (let c = 0; c < 34; c++) {
    const date = addDays(PERIOD[0], c);
    ctx.fillText(String(Number(date.slice(8))), x0 + c * colW + 22, top - 24);
  }
  names.forEach((name, r) => {
    const y = top + 20 + r * rowH;
    ctx.font = "30px sans-serif";
    ctx.fillText(name, 110, y + 55);
    ctx.font = "bold 28px sans-serif";
    for (let c = 0; c < 34; c++) {
      ctx.strokeStyle = "#999";
      ctx.strokeRect(x0 + c * colW, y, colW, rowH);
      const code = r === 2 ? PATTERN[c % PATTERN.length] : PATTERN[(c + r * 3) % PATTERN.length];
      ctx.fillText(code.replace("/", " "), x0 + c * colW + 8, y + 55);
    }
  });
  const blob = await new Promise((resolve) => canvas.toBlob(resolve, "image/jpeg", 0.9));
  return new File([blob], "roster.jpg", { type: "image/jpeg" });
};

mock.loadRoster = async () => {
  const input = document.getElementById("photo");
  const dt = new DataTransfer();
  dt.items.add(await mock.rosterFile());
  input.files = dt.files;
  input.dispatchEvent(new Event("change"));
};

console.info("Mock mode: Anthropic and Google are simulated.");
