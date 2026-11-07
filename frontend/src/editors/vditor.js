import Vditor from 'vditor';
import 'vditor/dist/index.css';
import { SK } from '../lib/context.js';

const FULL_TOOLBAR = [
  'headings', 'bold', 'italic', 'strike', 'link', '|',
  'list', 'ordered-list', 'check', '|',
  'quote', 'line', 'code', 'inline-code', '|',
  'table', '|', 'undo', 'redo',
];

const COMPACT_TOOLBAR = [
  'bold', 'italic', 'strike', '|',
  'list', 'ordered-list', '|',
  'link', 'code', 'inline-code',
];

export function createVditorEditor(root, value, options = {}) {
  return new Promise((resolve, reject) => {
    root.innerHTML = '';
    let instance = null;
    let settled = false;
    const timer = setTimeout(() => {
      if (settled) return;
      settled = true;
      try {
        instance?.destroy();
      } catch {
        // ignore
      }
      reject(new Error('Vditor 加载超时，请检查前端资源是否已构建。'));
    }, 20000);

    const controller = () => ({
      async getMarkdown() {
        return instance ? instance.getValue() : '';
      },
      async destroy() {
        try {
          instance?.destroy();
        } catch {
          // ignore
        }
        root.innerHTML = '';
      },
      focus() {
        try {
          instance?.focus();
        } catch {
          // ignore
        }
      },
    });

    try {
      instance = new Vditor(root, {
        mode: 'ir',
        value: value || '',
        height: options.compact ? 170 : (options.height || 560),
        minHeight: options.compact ? 120 : 280,
        cdn: SK.vditorCdn,
        theme: options.theme === 'dark' ? 'dark' : 'classic',
        lang: 'zh_CN',
        icon: 'ant',
        cache: { enable: false },
        placeholder: options.placeholder || '使用 Markdown 书写；支持 $$LaTeX$$、@提及 与 &Key 引用。',
        toolbar: options.compact ? COMPACT_TOOLBAR : FULL_TOOLBAR,
        toolbarConfig: { hide: false, pin: true },
        counter: { enable: false },
        preview: {
          theme: { current: options.theme === 'dark' ? 'dark' : 'light' },
          math: { engine: 'KaTeX', inlineDigit: true },
        },
        input: (markdown) => {
          if (typeof options.onInput === 'function') options.onInput(markdown);
        },
        after: () => {
          if (settled) {
            try {
              instance?.destroy();
            } catch {
              // ignore
            }
            return;
          }
          settled = true;
          clearTimeout(timer);
          resolve(controller());
        },
      });
    } catch (error) {
      clearTimeout(timer);
      settled = true;
      reject(error);
    }
  });
}
