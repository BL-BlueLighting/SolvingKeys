import React, { useEffect, useRef } from 'react';
import { createRoot } from 'react-dom/client';
import { useCreateBlockNote } from '@blocknote/react';
import { BlockNoteView } from '@blocknote/mantine';
import { BlockNoteSchema, defaultBlockSpecs, defaultInlineContentSpecs } from '@blocknote/core';
import {
  createReactInlineMathSpec,
  createReactMathBlockSpec,
} from '@blocknote/math-block';
import '@blocknote/core/fonts/inter.css';
import '@blocknote/mantine/style.css';
import '@blocknote/math-block/style.css';

const schema = BlockNoteSchema.create({
  blockSpecs: {
    ...defaultBlockSpecs,
    mathBlock: createReactMathBlockSpec(),
  },
  inlineContentSpecs: {
    ...defaultInlineContentSpecs,
    math: createReactInlineMathSpec(),
  },
});

function EditorHost({ initialMarkdown, theme, onReady }) {
  const editor = useCreateBlockNote({ schema });
  const initialized = useRef(false);

  useEffect(() => {
    if (initialized.current) return;
    initialized.current = true;
    try {
      if (initialMarkdown && initialMarkdown.trim() !== '') {
        const blocks = editor.tryParseMarkdownToBlocks(initialMarkdown);
        if (blocks.length > 0) {
          editor.replaceBlocks(editor.document, blocks);
        }
      }
    } catch (error) {
      console.warn('BlockNote could not parse the initial Markdown.', error);
    }
    onReady(editor);
  }, [editor, initialMarkdown, onReady]);

  return React.createElement(BlockNoteView, {
    editor,
    theme: theme === 'dark' ? 'dark' : 'light',
  });
}

export function createBlockNoteEditor(root, value, options = {}) {
  return new Promise((resolve, reject) => {
    root.innerHTML = '';
    root.classList.add('sk-blocknote-root');
    const reactRoot = createRoot(root);
    let editor = null;
    let settled = false;
    const timer = setTimeout(() => {
      if (settled) return;
      settled = true;
      try {
        reactRoot.unmount();
      } catch {
        // ignore
      }
      reject(new Error('BlockNote 加载超时，请检查前端资源是否已构建。'));
    }, 20000);

    const controller = {
      async getMarkdown() {
        return editor ? editor.blocksToMarkdownLossy() : value || '';
      },
      async destroy() {
        clearTimeout(timer);
        try {
          reactRoot.unmount();
        } catch {
          // ignore
        }
        root.innerHTML = '';
        root.classList.remove('sk-blocknote-root');
      },
      focus() {
        try {
          editor?.focus();
        } catch {
          // ignore
        }
      },
    };

    const onReady = (instance) => {
      if (settled) return;
      settled = true;
      clearTimeout(timer);
      editor = instance;
      resolve(controller);
    };

    try {
      reactRoot.render(React.createElement(EditorHost, {
        initialMarkdown: value || '',
        theme: options.theme,
        onReady,
      }));
    } catch (error) {
      clearTimeout(timer);
      settled = true;
      reject(error);
    }
  });
}
