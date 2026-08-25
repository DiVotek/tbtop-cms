import { render } from "@testing-library/react";
import { expect, it } from "bun:test";
import { ContactForm } from "./blocks/ContactForm";

const props = {
    heading: "Get in touch",
    submitLabel: "Send it",
    successMessage: "Thanks!",
    action: "/_cms/forms/1/form-1",
    honeypotField: "website",
    fields: [
        { name: "name", label: "Name", type: "text", required: true },
        { name: "message", label: "Message", type: "textarea", required: true },
    ],
};

it("renders a control for each configured field plus the copy", () => {
    const { getByTestId, getByText, container } = render(<ContactForm props={props} />);

    expect(getByTestId("contact-form")).toBeDefined();
    expect(getByText("Get in touch")).toBeDefined();
    expect(getByText("Send it")).toBeDefined();
    expect(container.querySelectorAll("input")).toHaveLength(2);
    expect(container.querySelectorAll("textarea")).toHaveLength(1);

    const honeypot = container.querySelector<HTMLInputElement>('input[name="website"]');
    expect(honeypot).not.toBeNull();
    expect(honeypot?.type).toBe("text");
    expect(honeypot?.tabIndex).toBe(-1);
    expect(honeypot?.autocomplete).toBe("off");
    expect(honeypot?.value).toBe("");
    expect(honeypot?.parentElement?.getAttribute("aria-hidden")).toBe("true");
});
