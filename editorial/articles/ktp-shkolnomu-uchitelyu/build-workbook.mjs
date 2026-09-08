import fs from "node:fs/promises";
import { SpreadsheetFile, Workbook } from "@oai/artifact-tool";

const workbook = Workbook.create();
const template = workbook.worksheets.add("Шаблон");
const example = workbook.worksheets.add("Пример 5 класс");

function baseSheet(sheet, title) {
  sheet.showGridLines = false;
  sheet.getRange("A2:F2").merge();
  sheet.getRange("A2").values = [[title]];
  sheet.getRange("A2").format.font = { name: "Arial", size: 14, bold: true, color: "#24332D" };
  sheet.getRange("A3:F3").format.borders = { bottom: { style: "thin", color: "#8FA59B" } };
  sheet.getRange("A5:A8").values = [["Предмет"], ["Класс"], ["Учебный год"], ["Основание"]];
  sheet.getRange("A5:A8").format.font = { name: "Arial", size: 10, bold: true, color: "#24332D" };
  sheet.getRange("B5:F8").format.font = { name: "Arial", size: 10, color: "#24332D" };
  sheet.getRange("A10:F10").values = [["№ занятия", "Раздел и тема", "Часы", "Плановая дата", "Фактическая дата", "Примечание"]];
  sheet.getRange("A10:F10").format.fill = "#426A5A";
  sheet.getRange("A10:F10").format.font = { name: "Arial", size: 10, bold: true, color: "#FFFFFF" };
  sheet.getRange("A10:F10").format.horizontalAlignment = "center";
  sheet.getRange("A10:F10").format.verticalAlignment = "center";
  sheet.getRange("A10:F10").format.wrapText = true;
  sheet.getRange("A:F").format.font = { name: "Arial", size: 10, color: "#24332D" };
  sheet.getRange("A:A").format.columnWidth = 12;
  sheet.getRange("B:B").format.columnWidth = 42;
  sheet.getRange("C:C").format.columnWidth = 10;
  sheet.getRange("D:E").format.columnWidth = 16;
  sheet.getRange("F:F").format.columnWidth = 38;
  sheet.getRange("C11:C60").setNumberFormat("0");
  sheet.getRange("D11:E60").format.numberFormat = "dd.mm.yyyy";
  sheet.getRange("A10:F60").format.verticalAlignment = "top";
  sheet.getRange("A10:F60").format.wrapText = true;
  sheet.getRange("A10:F60").format.borders = {
    insideHorizontal: { style: "thin", color: "#DDE5E1" },
    bottom: { style: "thin", color: "#8FA59B" },
  };
  sheet.freezePanes.freezeRows(10);
}

baseSheet(template, "Календарно-тематическое планирование — редактируемый шаблон");
template.getRange("B5:B8").values = [[""], [""], ["2026/27"], ["Рабочая программа и применимая федеральная рабочая программа"]];
template.getRange("B5:F8").format.fill = "#FFF4CC";
const blankRows = [];
for (let i = 1; i <= 40; i += 1) blankRows.push([i, "", null, null, null, ""]);
template.getRange("A11:F50").values = blankRows;
template.getRange("A51:B51").merge();
template.getRange("A51").values = [["Итого часов"]];
template.getRange("C51").formulas = [["=SUM(C11:C50)"]];
template.getRange("A51:F51").format.fill = "#E8F0EC";
template.getRange("A51:F51").format.font = { name: "Arial", size: 10, bold: true, color: "#24332D" };
template.getRange("A54:F57").values = [[
  "Как использовать",
  "Заполните предмет, класс и точное основание. Состав граф уточните по локальному порядку школы. Фактическую дату вносите после проведённого урока. Примечание — дополнительное, а не обязательное поле.",
  "", "", "", ""
], [
  "Проверка",
  "Сверьте итог часов с рабочей программой, календарным учебным графиком и расписанием конкретного класса.",
  "", "", "", ""
], [
  "Важно",
  "Этот файл — авторский редактируемый образец, а не государственно утверждённая форма.",
  "", "", "", ""
], [
  "Источник",
  "https://edsoo.ru/rabochie-programmy/",
  "", "", "", ""
]];
template.getRange("A54:A57").format.font = { name: "Arial", size: 10, bold: true, color: "#426A5A" };
template.getRange("B54:F57").format.wrapText = true;

baseSheet(example, "Заполненный пример: математика, 5 класс, 1–11 сентября 2026 года");
example.getRange("B5:F8").values = [[
  "Математика (базовый уровень)", "", "", "", ""
], [
  "5А", "", "", "", ""
], [
  "2026/27", "", "", "", ""
], [
  "ФРП ООО «Математика. 5–9 классы (базовый уровень)», тематическое планирование, 5 класс, раздел «Натуральные числа. Действия с натуральными числами» — 43 часа", "", "", "", ""
]];
const rows = [
  [1, "Десятичная система счисления. Запись натуральных чисел", 1, new Date(2026, 8, 1), new Date(2026, 8, 1), "Авторская детализация содержания ФРП. Результат: читать и записывать натуральные числа."],
  [2, "Ряд натуральных чисел. Число 0", 1, new Date(2026, 8, 2), new Date(2026, 8, 2), "Проверка понимания: объяснить место числа в натуральном ряду; не объявляется контрольной работой."],
  [3, "Сравнение натуральных чисел", 1, new Date(2026, 8, 3), new Date(2026, 8, 3), "Результат: сравнивать и упорядочивать натуральные числа."],
  [4, "Упорядочивание натуральных чисел", 1, new Date(2026, 8, 4), new Date(2026, 8, 4), "Задание: обосновать порядок чисел разными способами."],
  [5, "Координатная прямая: изображение натуральных чисел", 1, new Date(2026, 8, 7), new Date(2026, 8, 8), "07.09 урок не состоялся; тема перенесена по школьному порядку на ближайший урок."],
  [6, "Координаты точек на координатной прямой", 1, new Date(2026, 8, 8), new Date(2026, 8, 9), "Результат: находить координаты точки."],
  [7, "Сравнение чисел с помощью координатной прямой", 1, new Date(2026, 8, 9), new Date(2026, 8, 10), "Короткая самостоятельная проверка понимания по ходу урока; статус в графике процедур определяет школа."],
  [8, "Округление натуральных чисел", 1, new Date(2026, 8, 10), new Date(2026, 8, 11), "Результат: использовать правило округления натуральных чисел."],
];
example.getRange("A11:F18").values = rows;
example.getRange("D11:E18").format.numberFormat = "dd.mm.yyyy";
example.getRange("A19:B19").merge();
example.getRange("A19").values = [["Итого по фрагменту"]];
example.getRange("C19").formulas = [["=SUM(C11:C18)"]];
example.getRange("F19").values = [["8 часов проведено; в разделе остаётся 35 из 43 часов."]];
example.getRange("A19:F19").format.fill = "#E8F0EC";
example.getRange("A19:F19").format.font = { name: "Arial", size: 10, bold: true, color: "#24332D" };
example.getRange("A22:F26").values = [[
  "Условие примера",
  "Расписание 5А условное: математика каждый учебный день, понедельник–пятница. 7 сентября 2026 года урок условно не состоялся. Даты календаря и дни недели проверены; это не календарный график конкретной школы.",
  "", "", "", ""
], [
  "Из источника",
  "Название раздела, 43 часа, содержание и основные виды деятельности взяты из ФРП.",
  "", "", "", ""
], [
  "Авторское решение",
  "Деление содержания на восемь уроков, формулировки тем, расписание, даты, задания и перенос созданы для примера.",
  "", "", "", ""
], [
  "Источник",
  "https://edsoo.ru/wp-content/uploads/2025/06/05_frp_matematika-5-9-klassy_baza_17062025_itog-na-sajt.pdf",
  "", "", "", ""
], [
  "Арифметика",
  "8 × 1 час = 8 часов; 43 − 8 = 35 часов остаётся в разделе.",
  "", "", "", ""
]];
example.getRange("A22:A26").format.font = { name: "Arial", size: 10, bold: true, color: "#426A5A" };
example.getRange("B22:F26").format.wrapText = true;

workbook.recalculate();
const checkTemplate = await workbook.inspect({ kind: "table", range: "Шаблон!A1:F57", include: "values,formulas", tableMaxRows: 57, tableMaxCols: 6 });
const checkExample = await workbook.inspect({ kind: "table", range: "Пример 5 класс!A1:F26", include: "values,formulas", tableMaxRows: 26, tableMaxCols: 6 });
const errors = await workbook.inspect({ kind: "match", searchTerm: "#REF!|#DIV/0!|#VALUE!|#NAME\\?|#N/A|#NUM!|#NULL!|#SPILL!|#CALC!", options: { useRegex: true, maxResults: 300 }, summary: "final formula error scan" });
await fs.mkdir("assets/files/blog", { recursive: true });
await fs.mkdir("editorial/articles/ktp-shkolnomu-uchitelyu/previews", { recursive: true });
const templatePreview = await workbook.render({ sheetName: "Шаблон", range: "A1:F26", scale: 1 });
await fs.writeFile("editorial/articles/ktp-shkolnomu-uchitelyu/previews/template.png", new Uint8Array(await templatePreview.arrayBuffer()));
const examplePreview = await workbook.render({ sheetName: "Пример 5 класс", range: "A1:F26", scale: 1 });
await fs.writeFile("editorial/articles/ktp-shkolnomu-uchitelyu/previews/example.png", new Uint8Array(await examplePreview.arrayBuffer()));
const output = await SpreadsheetFile.exportXlsx(workbook);
await output.save("assets/files/blog/ktp-template-and-example-2026-27.xlsx");
console.log(checkTemplate.ndjson);
console.log(checkExample.ndjson);
console.log(errors.ndjson);
