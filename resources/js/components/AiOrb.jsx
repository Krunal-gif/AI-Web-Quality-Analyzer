// The AI's visual presence: a small living orb (styles in app.css → .ai-orb).
// state: 'idle' (model ready), 'thinking' (a request is running) or 'off' (Ollama not reachable).
export default function AiOrb({ size = 16, state = 'idle', className = '' }) {
    return <span aria-hidden className={`ai-orb ${className}`} data-state={state} style={{ width: size, height: size }} />;
}
