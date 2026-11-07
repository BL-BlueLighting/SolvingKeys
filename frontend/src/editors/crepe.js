import { Crepe } from '@milkdown/crepe';
import '@milkdown/crepe/theme/common/style.css';

export async function createCrepeEditor(root, value, options = {}) {
  if (options.theme === 'dark') {
    await import('@milkdown/crepe/theme/frame-dark.css');
  } else {
    await import('@milkdown/crepe/theme/frame.css');
  }

  root.innerHTML = '';
  root.classList.add('sk-crepe-root');
  const crepe = new Crepe({
    root,
    defaultValue: value || '',
  });
  await crepe.create();

  return {
    async getMarkdown() {
      return crepe.getMarkdown();
    },
    async destroy() {
      try {
        await crepe.destroy();
      } catch {
        // ignore
      }
      root.innerHTML = '';
      root.classList.remove('sk-crepe-root');
    },
    focus() {
      try {
        root.querySelector('.ProseMirror')?.focus();
      } catch {
        // ignore
      }
    },
  };
}
