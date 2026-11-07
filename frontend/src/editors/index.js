export const EDITOR_MODES = [
  { id: 'vditor', label: 'Vditor', hint: '即时渲染，启动最快' },
  { id: 'milkdown', label: 'Milkdown', hint: 'Crepe / ProseMirror' },
  { id: 'blocknote', label: 'BlockNote', hint: '块编辑，Notion 风格' },
];

const STORAGE_KEY = 'sk:editorMode';

export function getPreferredMode() {
  const stored = localStorage.getItem(STORAGE_KEY);
  return EDITOR_MODES.some((mode) => mode.id === stored) ? stored : 'vditor';
}

export function setPreferredMode(mode) {
  if (EDITOR_MODES.some((entry) => entry.id === mode)) {
    localStorage.setItem(STORAGE_KEY, mode);
  }
}

export async function createEditor(mode, root, value, options = {}) {
  const selected = EDITOR_MODES.some((entry) => entry.id === mode) ? mode : 'vditor';
  const theme = document.documentElement.dataset.bsTheme === 'dark' ? 'dark' : 'light';
  const settings = { ...options, theme };
  const onInput = typeof options.onInput === 'function' ? options.onInput : null;

  root.classList.toggle('sk-editor-compact', !!options.compact);
  root.classList.add('sk-editor-surface');

  let factory;
  if (selected === 'milkdown') {
    factory = (await import('./crepe.js')).createCrepeEditor;
  } else if (selected === 'blocknote') {
    factory = (await import('./blocknote.js')).createBlockNoteEditor;
  } else {
    factory = (await import('./vditor.js')).createVditorEditor;
  }

  const controller = await factory(root, value, settings);
  const inputHandler = () => {
    if (onInput) onInput();
  };
  root.addEventListener('input', inputHandler, true);
  const originalDestroy = controller.destroy.bind(controller);
  controller.destroy = async () => {
    root.removeEventListener('input', inputHandler, true);
    root.classList.remove('sk-editor-surface', 'sk-editor-compact');
    await originalDestroy();
  };
  return { ...controller, mode: selected };
}
