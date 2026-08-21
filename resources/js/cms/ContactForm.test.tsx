import { render } from "@testing-library/react";
import { expect, it } from "bun:test";
import { ContactForm } from "./blocks/ContactForm";
import { contactFormProps } from "./catalog";

const props = {
    heading: "Get in touch",
    submitLabel: "Send it",
    successMessage: "Thanks!",
    action: "/_cms/forms/1/form-1",
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
    expect(container.querySelectorAll("input")).toHaveLength(1);
    expect(container.querySelectorAll("textarea")).toHaveLength(1);
});

it("accepts resolver props before the form action is bound", () => {
    const { action } = contactFormProps.parse({
        heading: props.heading,
        submitLabel: props.submitLabel,
        successMessage: props.successMessage,
        fields: props.fields,
    });

    expect(action).toBe("");
});
