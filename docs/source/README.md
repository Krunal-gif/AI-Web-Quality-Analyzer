# Document sources

The PDFs in `docs/` are printed from these HTML files with headless Chrome. Edit the HTML, then run
from the project folder:

```bash
google-chrome --headless=new --no-pdf-header-footer --virtual-time-budget=10000 \
  --print-to-pdf=docs/1-Developer-Guide-How-To-Run-And-How-It-Works.pdf "file://$PWD/docs/source/developer-guide.html"

google-chrome --headless=new --no-pdf-header-footer --virtual-time-budget=10000 \
  --print-to-pdf=docs/2-Build-Log-Step-By-Step.pdf "file://$PWD/docs/source/build-log.html"
```

- `developer-guide.html`: one `<section class="slide">` per slide (1280×720). Slide numbers are automatic.
- `build-log.html`: A4, flows over pages on its own. Add a step by copying a `<div class="step">` block.
- Fonts come from Google Fonts, so print while online. `--virtual-time-budget` gives them time to load.

## Zero to Hero learning guide

`zero-to-hero.html` is the source of `docs/3-Zero-To-Hero-Learning-Guide.pdf` (A4). Its code examples are not
copied by hand: the build script fills them from the real files, and adds every source file as Appendix B.
Rebuild it after changing the code (needs python3 with pygments, and Chrome):

```bash
python3 docs/source/build-zero-to-hero.py
```

## Run guide and presentation prep

`run-guide.html` is the source of `docs/4-Run-Guide-And-Presentation-Prep.pdf` (A4): what the project is, the 59 rules explained, setup and
run steps, common errors with fixes, a command cheat sheet and presentation notes. Print it the same way:

```bash
google-chrome --headless=new --no-pdf-header-footer --virtual-time-budget=10000 \
  --print-to-pdf=docs/4-Run-Guide-And-Presentation-Prep.pdf "file://$PWD/docs/source/run-guide.html"
```

## Exam presentation

`exam-presentation.html` is the source of `docs/5-Exam-Presentation-How-The-Code-Works.pdf`: 23 slides (1280×720)
that explain how the code works, from loading a target to verifying a fix, with a demo script and likely examiner
questions. Print it the same way:

```bash
google-chrome --headless=new --no-pdf-header-footer --virtual-time-budget=10000 \
  --print-to-pdf=docs/5-Exam-Presentation-How-The-Code-Works.pdf "file://$PWD/docs/source/exam-presentation.html"
```
