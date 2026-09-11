import { Marked } from 'marked';

const markdown = new Marked({
  gfm: true,
  renderer: {
    // Raw HTML is not a feature of this notebook. Angular also sanitizes
    // the resulting string at the [innerHTML] binding (including link URLs).
    html: () => '',
    checkbox: ({ checked }) => checked ? '☑ ' : '☐ ',
  },
});

export function renderPreparationMarkdown(source: string): string {
  return markdown.parse(source, { async: false });
}
